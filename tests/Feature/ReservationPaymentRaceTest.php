<?php

namespace Tests\Feature;

use App\Jobs\ReconcilePaidOrdersJob;
use App\Jobs\ReleaseExpiredReservationsJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeSessionStatus;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — reservation-vs-payment race fix.
 *
 * Covers the money-in/no-tickets bug: a slow-but-successful Stripe payment that
 * lands AFTER its reservation window elapsed. The fix is three-fold and each
 * part is asserted here against the real MySQL test DB (so the FOR UPDATE row
 * locks behave as in production), with Stripe mocked via the container-bound
 * {@see FakeStripePaymentService}:
 *
 *   1. The sweeper ({@see ReleaseExpiredReservationsJob}) NEVER releases a paid
 *      order's seat on the timer alone — it asks Stripe first and only releases
 *      when Stripe positively reports the Session expired. A PAID session is
 *      confirmed instead; an UNKNOWN (Stripe unreachable) keeps the hold.
 *   2. The webhook ({@see WebhookProcessor}) is authoritative: it confirms an
 *      order that was already swept to `expired`, un-voiding tickets and
 *      re-committing capacity (honouring the seats).
 *   3. The reconcile command ({@see ReconcilePaidOrdersJob}) recovers a
 *      paid-but-unconfirmed order through the same authoritative path.
 */
class ReservationPaymentRaceTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    private function runSweep(): void
    {
        app()->call([new ReleaseExpiredReservationsJob, 'handle']);
    }

    /**
     * Seed a paid-capable reserved Order whose window has elapsed, holding `qty`
     * units of $type (reserved_count + ticket rows), with a Stripe session id
     * and a connected account on its Company.
     *
     * @return array{0: Order, 1: string} [order, sessionId]
     */
    private function expiredPaidOrder(Event $event, TicketType $type, int $qty, string $sessionId): array
    {
        Company::withoutGlobalScopes()->whereKey($event->company_id)
            ->update(['stripe_account_id' => 'acct_race_test']);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
            'reserved_until' => now()->subSeconds(1),
            'order_total_minor' => 2000,
            'stripe_session_id' => $sessionId,
        ]);

        for ($i = 0; $i < $qty; $i++) {
            Ticket::withoutGlobalScopes()->create([
                'company_id' => $event->company_id,
                'order_id' => $order->id,
                'ticket_type_id' => $type->id,
                'status' => Ticket::STATUS_VALID,
            ]);
        }

        $type->increment('reserved_count', $qty);

        return [$order, $sessionId];
    }

    // ---- 1. Sweeper consults Stripe before releasing a paid order ----------

    public function test_sweeper_keeps_hold_and_confirms_when_stripe_reports_paid(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'sold_count' => 0, 'reserved_count' => 0,
        ]);

        [$order, $sessionId] = $this->expiredPaidOrder($event, $type, 4, 'cs_paid_race');

        // Stripe says the payment SUCCEEDED (the slow payment landed).
        $this->fakeStripe()->setCheckoutSessionStatus($sessionId, StripeSessionStatus::PAID, 'pi_paid_race');

        $this->runSweep();

        // The seat was NOT released; the order is confirmed paid instead.
        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_PAID, $fresh->status);
        $this->assertSame('pi_paid_race', $fresh->stripe_payment_intent_id);
        // Capacity moved reserved -> sold (held seat committed, no oversell).
        $this->assertSame(0, $type->fresh()->reserved_count);
        $this->assertSame(4, $type->fresh()->sold_count);
        // Tickets stay valid.
        $this->assertSame(4, Ticket::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('status', Ticket::STATUS_VALID)->count());
    }

    public function test_sweeper_keeps_hold_when_stripe_status_is_unknown(): void
    {
        // Stripe unreachable / ambiguous => fail safe, keep the hold.
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'reserved_count' => 0,
        ]);

        [$order] = $this->expiredPaidOrder($event, $type, 3, 'cs_unknown_race');
        // No arranged status => UNKNOWN from the fake (mirrors the real fail-safe).

        $this->runSweep();

        $this->assertSame(Order::STATUS_RESERVED, $order->fresh()->status);
        $this->assertSame(3, $type->fresh()->reserved_count, 'Hold must be kept when Stripe cannot confirm.');
    }

    public function test_sweeper_releases_only_when_stripe_confirms_expired(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'reserved_count' => 0,
        ]);

        [$order, $sessionId] = $this->expiredPaidOrder($event, $type, 5, 'cs_expired_race');
        $this->fakeStripe()->setCheckoutSessionStatus($sessionId, StripeSessionStatus::EXPIRED);

        $this->runSweep();

        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->reserved_count);
        $this->assertSame(5, Ticket::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('status', Ticket::STATUS_VOIDED)->count());
    }

    public function test_sweeper_still_releases_free_orders_with_no_session(): void
    {
        // A free/no-session order has no Stripe payment to protect: released on
        // the timer exactly as before (no Stripe call needed).
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->free()->create([
            'status' => Order::STATUS_RESERVED,
            'reserved_until' => now()->subSeconds(1),
            'stripe_session_id' => null,
        ]);
        Ticket::withoutGlobalScopes()->create([
            'company_id' => $event->company_id, 'order_id' => $order->id,
            'ticket_type_id' => $type->id, 'status' => Ticket::STATUS_VALID,
        ]);
        $type->increment('reserved_count', 1);

        $this->runSweep();

        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->reserved_count);
        $this->assertSame([], $this->fakeStripe()->sessionStatusCalls, 'No Stripe call for a free/no-session order.');
    }

    // ---- 2. Webhook is authoritative: recovers an already-expired order -----

    public function test_webhook_recovers_an_already_expired_order(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        // The hold was already released by the old sweep: 0 reserved, and the
        // tickets are voided — the exact post-expiry state.
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'sold_count' => 0, 'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_EXPIRED,
            'order_total_minor' => 2000,
            'stripe_session_id' => 'cs_recover',
        ]);
        for ($i = 0; $i < 4; $i++) {
            Ticket::withoutGlobalScopes()->create([
                'company_id' => $event->company_id, 'order_id' => $order->id,
                'ticket_type_id' => $type->id, 'status' => Ticket::STATUS_VOIDED,
            ]);
        }

        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_recover_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_recover', 'payment_intent' => 'pi_recover'],
        ));

        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_PAID, $fresh->status, 'Payment is authoritative: expired order is recovered to paid.');
        $this->assertSame('pi_recover', $fresh->stripe_payment_intent_id);
        // Tickets un-voided.
        $this->assertSame(4, Ticket::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('status', Ticket::STATUS_VALID)->count());
        // Seats honoured: committed straight to sold (hold was already gone).
        $this->assertSame(4, $type->fresh()->sold_count);
    }

    public function test_recovery_does_not_consume_other_customers_live_holds(): void
    {
        // Another Customer holds 3 seats of the same type when an expired order
        // is recovered. Only the recovered order's 4 units may reach sold_count;
        // the other hold must stay in reserved_count untouched.
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'sold_count' => 0, 'reserved_count' => 3,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_EXPIRED,
            'order_total_minor' => 2000,
            'stripe_session_id' => 'cs_other_holds',
        ]);
        for ($i = 0; $i < 4; $i++) {
            Ticket::withoutGlobalScopes()->create([
                'company_id' => $event->company_id, 'order_id' => $order->id,
                'ticket_type_id' => $type->id, 'status' => Ticket::STATUS_VOIDED,
            ]);
        }

        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_other_holds_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_other_holds', 'payment_intent' => 'pi_other_holds'],
        ));

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(4, $type->fresh()->sold_count, 'Only the recovered order is counted as sold.');
        $this->assertSame(3, $type->fresh()->reserved_count, 'Other Customers\' holds are left alone.');
    }

    public function test_recovery_honours_seats_even_when_event_is_now_full(): void
    {
        // The last seats were resold in the gap: committing the recovered order
        // tips the event over capacity. Payment is authoritative, so it is
        // honoured (committed) rather than refused.
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 4, 'sold_count' => 4, 'reserved_count' => 0, // already full
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_EXPIRED,
            'order_total_minor' => 1000,
            'stripe_session_id' => 'cs_oversell',
        ]);
        Ticket::withoutGlobalScopes()->create([
            'company_id' => $event->company_id, 'order_id' => $order->id,
            'ticket_type_id' => $type->id, 'status' => Ticket::STATUS_VOIDED,
        ]);

        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_oversell_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_oversell', 'payment_intent' => 'pi_oversell'],
        ));

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        // Honoured: sold_count went to 5 against a capacity of 4.
        $this->assertSame(5, $type->fresh()->sold_count);
    }

    public function test_webhook_still_confirms_a_still_reserved_order_normally(): void
    {
        // The common post-fix path: seat still held (window aligned to Stripe),
        // ordinary reserved -> sold commit, no re-grab.
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'sold_count' => 0, 'reserved_count' => 2,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
            'order_total_minor' => 2000,
            'stripe_session_id' => 'cs_normal',
        ]);
        for ($i = 0; $i < 2; $i++) {
            Ticket::withoutGlobalScopes()->create([
                'company_id' => $event->company_id, 'order_id' => $order->id,
                'ticket_type_id' => $type->id, 'status' => Ticket::STATUS_VALID,
            ]);
        }

        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_normal_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_normal', 'payment_intent' => 'pi_normal'],
        ));

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->reserved_count);
        $this->assertSame(2, $type->fresh()->sold_count);
    }

    // ---- 3. Reconcile command recovers a paid-but-unconfirmed order ---------

    public function test_reconcile_recovers_a_single_paid_but_expired_order(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'sold_count' => 0, 'reserved_count' => 0,
        ]);

        [$order, $sessionId] = $this->expiredPaidOrder($event, $type, 4, 'cs_reconcile');
        // Simulate the old-code aftermath: swept to expired, tickets voided,
        // hold already released (reserved_count back to 0).
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => Order::STATUS_EXPIRED]);
        Ticket::withoutGlobalScopes()->where('order_id', $order->id)->update(['status' => Ticket::STATUS_VOIDED]);
        $type->update(['reserved_count' => 0]);

        // Stripe confirms the charge really succeeded.
        $this->fakeStripe()->setCheckoutSessionStatus($sessionId, StripeSessionStatus::PAID, 'pi_reconcile');

        $job = new ReconcilePaidOrdersJob($order->order_reference);
        app()->call([$job, 'handle']);

        $this->assertSame(1, $job->summary['recovered']);
        $fresh = $order->fresh();
        $this->assertSame(Order::STATUS_PAID, $fresh->status);
        $this->assertSame('pi_reconcile', $fresh->stripe_payment_intent_id);
        $this->assertSame(4, $type->fresh()->sold_count);
        $this->assertSame(4, Ticket::withoutGlobalScopes()
            ->where('order_id', $order->id)->where('status', Ticket::STATUS_VALID)->count());
    }

    public function test_reconcile_leaves_an_order_untouched_when_stripe_not_paid(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100, 'reserved_count' => 0,
        ]);

        [$order, $sessionId] = $this->expiredPaidOrder($event, $type, 2, 'cs_notpaid');
        Order::withoutGlobalScopes()->whereKey($order->id)->update(['status' => Order::STATUS_EXPIRED]);
        // Stripe reports the session genuinely expired/unpaid.
        $this->fakeStripe()->setCheckoutSessionStatus($sessionId, StripeSessionStatus::EXPIRED);

        $job = new ReconcilePaidOrdersJob($order->order_reference);
        app()->call([$job, 'handle']);

        $this->assertSame(0, $job->summary['recovered']);
        $this->assertSame(Order::STATUS_EXPIRED, $order->fresh()->status, 'Never confirm an unconfirmed payment.');
    }

    // ---- Window alignment: checkout passes expires_at to Stripe ------------

    public function test_reservation_window_is_one_hour(): void
    {
        $this->assertSame(3600, CapacityReservationService::RESERVATION_WINDOW_SECONDS);
    }
}
