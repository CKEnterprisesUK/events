<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 20.1.
 *
 * Covers OrderController cancel/refund (dashboard, Admin-gated) and the
 * refund/dispute webhook void handling, all converging on the shared
 * OrderCancellationService so the dashboard path and the webhook path stay
 * idempotent and consistent.
 *
 * Requirements:
 *   - 17.1 Admin/Owner may cancel and refund an Order (role-gated).
 *   - 17.2 Refunding a paid Order issues a Stripe refund on the connected
 *          account (Stripe mocked via the FakeStripePaymentService).
 *   - 17.3 Cancel/refund voids the Order's Tickets so the QR fails at scan.
 *   - 17.4 A refund webhook updates Order + Ticket status.
 *   - 17.5 A dispute webhook updates Order status.
 *
 * Capacity accounting is asserted throughout: a cancelled/refunded Order returns
 * the capacity it held (reserved or sold) so availability stays correct. Runs
 * against the real MySQL test DB so the FOR UPDATE capacity math behaves as in
 * production; Stripe is always mocked and no card data is involved.
 */
class OrderCancellationAndRefundTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * A confirmed/paid Order in the given Company with $qty tickets of one
     * Ticket_Type whose capacity is already committed to `sold_count`.
     *
     * @return array{0: Order, 1: TicketType, 2: Event}
     */
    private function soldOrder(
        Company $company,
        int $qty = 2,
        string $status = Order::STATUS_PAID,
        array $orderOverrides = [],
    ): array {
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => $qty,
            'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create(array_merge([
            'status' => $status,
            'fulfilled_at' => now(),
        ], $orderOverrides));

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type, $event];
    }

    /**
     * A still-reserved Order in the given Company holding $qty reserved units of
     * one Ticket_Type.
     *
     * @return array{0: Order, 1: TicketType, 2: Event}
     */
    private function reservedOrder(Company $company, int $qty = 2): array
    {
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => $qty,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type, $event];
    }

    private function assertAllTicketsVoided(Order $order): void
    {
        $this->assertSame(
            0,
            Ticket::withoutGlobalScopes()
                ->where('order_id', $order->getKey())
                ->where('status', Ticket::STATUS_VALID)
                ->count(),
            'All of the Order\'s Tickets should be voided.',
        );
    }

    // ---- Cancel (dashboard) --------------------------------------------------

    public function test_admin_cancels_a_reserved_order_releasing_the_reservation_and_voiding_tickets(): void
    {
        // Requirements 17.1, 17.3 + capacity: cancelling a reserved Order voids
        // its Tickets and returns the still-held reservation.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        [$order, $type] = $this->reservedOrder($company, qty: 3);

        $this->actingAs($admin)
            ->from('/dashboard')
            ->post("/dashboard/orders/{$order->id}/cancel")
            ->assertRedirect();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);

        // Reservation returned — nothing left held, nothing sold.
        $type->refresh();
        $this->assertSame(0, $type->reserved_count);
        $this->assertSame(0, $type->sold_count);
    }

    public function test_admin_cancels_a_confirmed_order_returning_sold_capacity_and_voiding_tickets(): void
    {
        // Requirements 17.1, 17.3 + capacity: cancelling a confirmed Order voids
        // its Tickets and returns the sold capacity.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        [$order, $type] = $this->soldOrder($company, qty: 2, status: Order::STATUS_FREE_CONFIRMED);

        $this->actingAs($admin)
            ->from('/dashboard')
            ->post("/dashboard/orders/{$order->id}/cancel")
            ->assertRedirect();

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);

        $type->refresh();
        $this->assertSame(0, $type->sold_count, 'Sold capacity should be returned on cancel.');

        // A free_confirmed cancel makes no Stripe refund.
        $this->assertCount(0, $this->fakeStripe()->refundCalls);
    }

    // ---- Refund (dashboard) --------------------------------------------------

    public function test_admin_refunds_a_paid_order_issues_stripe_refund_marks_refunded_voids_tickets_and_returns_capacity(): void
    {
        // Requirements 17.1, 17.2, 17.3 + capacity: refunding a paid Order
        // issues the Stripe refund on the connected account, marks the Order
        // refunded, voids its Tickets, and returns the sold capacity.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $company->stripe_account_id = 'acct_REFUND';
        $company->save();

        [$order, $type] = $this->soldOrder($company, qty: 2, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_charge_id' => 'ch_refundme',
            'order_total_minor' => 4_200,
        ]);

        $this->actingAs($admin)
            ->from('/dashboard')
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);

        $type->refresh();
        $this->assertSame(0, $type->sold_count, 'Sold capacity should be returned on refund.');

        // Requirement 17.2: exactly one Stripe refund on the connected account
        // for the charge and the Order total.
        $refunds = $this->fakeStripe()->refundCalls;
        $this->assertCount(1, $refunds);
        $this->assertSame('acct_REFUND', $refunds[0]['connected_account_id']);
        $this->assertSame('ch_refundme', $refunds[0]['charge_id']);
        $this->assertSame(4_200, $refunds[0]['amount_minor']);
    }

    public function test_refunding_a_free_confirmed_order_marks_refunded_without_calling_stripe(): void
    {
        // Requirement 17.2 boundary: a free_confirmed Order carries no charge,
        // so no Stripe refund is issued — but it is still voided.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        [$order, $type] = $this->soldOrder($company, qty: 1, status: Order::STATUS_FREE_CONFIRMED);

        $this->actingAs($admin)
            ->from('/dashboard')
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertCount(0, $this->fakeStripe()->refundCalls);

        $type->refresh();
        $this->assertSame(0, $type->sold_count);
    }

    // ---- Authorisation -------------------------------------------------------

    public function test_non_admin_cannot_cancel_or_refund_an_order(): void
    {
        // Requirement 17.1: only Admin/Owner may cancel/refund. A Scanner is
        // denied and the Order and its Tickets are left unchanged.
        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);

        [$order, $type] = $this->soldOrder($company, qty: 2, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_charge_id' => 'ch_denied',
        ]);

        $this->actingAs($scanner)
            ->post("/dashboard/orders/{$order->id}/cancel")
            ->assertForbidden();

        $this->actingAs($scanner)
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertForbidden();

        // Nothing changed: status intact, tickets valid, capacity untouched, no
        // Stripe call.
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(
            2,
            Ticket::withoutGlobalScopes()->where('order_id', $order->id)->where('status', Ticket::STATUS_VALID)->count(),
        );
        $this->assertSame(2, $type->fresh()->sold_count);
        $this->assertCount(0, $this->fakeStripe()->refundCalls);
    }

    public function test_cannot_cancel_another_companys_order(): void
    {
        // Cross-Company isolation: an Admin cannot reach another Company's Order
        // — the tenant scope 404s it, leaving it unchanged.
        $admin = User::factory()->admin()->create();
        $otherCompany = Company::factory()->create();

        [$order] = $this->soldOrder($otherCompany, qty: 1, status: Order::STATUS_PAID);

        $this->actingAs($admin)
            ->post("/dashboard/orders/{$order->id}/cancel")
            ->assertNotFound();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    // ---- Refund / dispute webhooks ------------------------------------------

    public function test_refund_webhook_marks_order_refunded_voids_tickets_and_returns_capacity(): void
    {
        // Requirement 17.4: a charge.refunded webhook marks the Order refunded,
        // voids its Tickets, and returns capacity.
        $company = Company::factory()->create();
        [$order, $type] = $this->soldOrder($company, qty: 2, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_hook_refund',
        ]);

        $event = new StripeWebhookEvent(
            id: 'evt_hook_refund_1',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_hook_refund'],
        );

        app(WebhookProcessor::class)->process($event);

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    public function test_refund_webhook_is_idempotent_on_redelivery(): void
    {
        // Requirement 17.4 idempotency: a redelivered charge.refunded (same
        // event id) is deduped and does not double-return capacity.
        $company = Company::factory()->create();
        [$order, $type] = $this->soldOrder($company, qty: 3, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_hook_dupe',
        ]);

        $event = new StripeWebhookEvent(
            id: 'evt_hook_refund_dupe',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_hook_dupe'],
        );

        $processor = app(WebhookProcessor::class);
        $this->assertTrue($processor->process($event));
        $this->assertFalse($processor->process($event), 'Redelivery should be deduped.');

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        // sold_count returned exactly once, never driven negative.
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    public function test_dispute_webhook_marks_order_disputed_and_voids_tickets(): void
    {
        // Requirement 17.5: a charge.dispute.created webhook marks the Order
        // disputed (and voids its Tickets via the shared path).
        $company = Company::factory()->create();
        [$order, $type] = $this->soldOrder($company, qty: 1, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_hook_dispute',
        ]);

        $event = new StripeWebhookEvent(
            id: 'evt_hook_dispute_1',
            type: 'charge.dispute.created',
            data: ['payment_intent' => 'pi_hook_dispute'],
        );

        app(WebhookProcessor::class)->process($event);

        $this->assertSame(Order::STATUS_DISPUTED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    // ---- Convergence: dashboard refund + later webhook ----------------------

    public function test_dashboard_refund_then_refund_webhook_do_not_double_apply(): void
    {
        // Requirements 17.2, 17.4: a dashboard refund followed by the eventual
        // charge.refunded webhook converge — one Stripe refund, capacity
        // returned once, Order refunded, Tickets voided.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $company->stripe_account_id = 'acct_CONVERGE';
        $company->save();

        [$order, $type] = $this->soldOrder($company, qty: 2, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_charge_id' => 'ch_converge',
            'stripe_payment_intent_id' => 'pi_converge',
            'order_total_minor' => 5_000,
        ]);

        // 1) Admin refunds via the dashboard: one Stripe refund, capacity back.
        $this->actingAs($admin)
            ->from('/dashboard')
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->sold_count);
        $this->assertCount(1, $this->fakeStripe()->refundCalls);

        // 2) Stripe later delivers the charge.refunded webhook for the same
        //    charge. It must NOT re-void, re-return capacity, or issue a second
        //    Stripe refund (the webhook path never calls Stripe anyway).
        $event = new StripeWebhookEvent(
            id: 'evt_converge_refund',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_converge'],
        );

        app(WebhookProcessor::class)->process($event);

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        // Capacity still returned exactly once (not driven negative).
        $this->assertSame(0, $type->fresh()->sold_count);
        // Still exactly one Stripe refund — the webhook made no additional one.
        $this->assertCount(1, $this->fakeStripe()->refundCalls);
    }

    public function test_refund_webhook_then_dashboard_refund_do_not_double_apply(): void
    {
        // The reverse ordering also converges: the webhook refunds first, then a
        // dashboard refund of the now-refunded Order is a no-op and issues no
        // Stripe refund.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $company->stripe_account_id = 'acct_REVERSE';
        $company->save();

        [$order, $type] = $this->soldOrder($company, qty: 2, status: Order::STATUS_PAID, orderOverrides: [
            'stripe_charge_id' => 'ch_reverse',
            'stripe_payment_intent_id' => 'pi_reverse',
        ]);

        // 1) Webhook refunds first.
        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_reverse_refund',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_reverse'],
        ));

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->sold_count);

        // 2) Admin then clicks refund — already terminal, so a no-op with no
        //    Stripe call and no further capacity change.
        $this->actingAs($admin)
            ->from('/dashboard')
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->sold_count);
        $this->assertCount(0, $this->fakeStripe()->refundCalls, 'No Stripe refund for an already-refunded Order.');
    }
}
