<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 20.3.
 *
 * Focused feature coverage for the refund/dispute WEBHOOK transitions handled
 * by {@see WebhookProcessor}, driven directly through the processor with Stripe
 * mocked via the container-bound {@see FakeStripePaymentService}. It complements
 * (rather than repeats) the broader cancel/refund coverage in
 * OrderCancellationAndRefundTest (task 20.1) and the end-to-end webhook pipeline
 * in StripeWebhookHandlingTest (task 16.1) by concentrating on the webhook
 * transition edges those files leave uncovered:
 *
 *   - Order resolution for the transition across ALL three keys the processor
 *     tries — stripe_session_id, stripe_payment_intent_id, and
 *     metadata.order_reference (17.4).
 *   - Dispute-webhook idempotency on redelivery, with capacity returned exactly
 *     once (17.5) — the mirror of the refund idempotency already covered in 20.1.
 *   - Graceful handling when the transition cannot resolve an Order, or the
 *     resolved Order is already terminal, so a redelivery neither errors nor
 *     double-applies (17.4, 17.5).
 *   - Convergence of the two terminal webhooks (refund then dispute, and the
 *     reverse) on the shared idempotent path: the first transition wins and the
 *     second is a no-op (17.4, 17.5).
 *
 * Requirements:
 *   - 17.2 Refunding a paid Order issues a Stripe refund on the connected
 *          account (Stripe mocked). The webhook path itself never calls Stripe;
 *          this file proves the refund transition performs no Stripe refund of
 *          its own.
 *   - 17.4 A charge.refunded webhook updates Order + Ticket status.
 *   - 17.5 A charge.dispute.created webhook updates Order status.
 *
 * Runs against the real MySQL test DB so the FOR UPDATE capacity math behaves as
 * in production. No live Stripe call and no card data are involved.
 */
class RefundDisputeWebhookTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    private function processor(): WebhookProcessor
    {
        return app(WebhookProcessor::class);
    }

    /**
     * A paid Order in the given Company with $qty tickets of one Ticket_Type
     * whose capacity is committed to `sold_count`, ready to be transitioned by a
     * refund/dispute webhook.
     *
     * @return array{0: Order, 1: TicketType}
     */
    private function paidOrder(Company $company, int $qty = 2, array $orderOverrides = []): array
    {
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => $qty,
            'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create(array_merge([
            'status' => Order::STATUS_PAID,
            'fulfilled_at' => now(),
        ], $orderOverrides));

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type];
    }

    private function assertAllTicketsVoided(Order $order): void
    {
        $this->assertSame(
            0,
            Ticket::withoutGlobalScopes()
                ->where('order_id', $order->getKey())
                ->where('status', Ticket::STATUS_VALID)
                ->count(),
            "All of the Order's Tickets should be voided.",
        );
    }

    // ---- Order resolution for the refund transition -------------------------

    public function test_refund_webhook_resolves_order_by_session_id_and_transitions_it(): void
    {
        // Requirement 17.4: the refund transition finds its Order by the Stripe
        // Checkout Session id, marks it refunded, voids Tickets, returns capacity.
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 2, orderOverrides: [
            'stripe_session_id' => 'cs_refund_bysession',
        ]);

        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_refund_session',
            type: 'charge.refunded',
            data: ['id' => 'cs_refund_bysession'],
        ));

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
        // The webhook transition performs no Stripe refund of its own.
        $this->assertCount(0, $this->fakeStripe()->refundCalls);
    }

    public function test_refund_webhook_resolves_order_by_metadata_order_reference_and_transitions_it(): void
    {
        // Requirement 17.4: when neither the session id nor the payment intent
        // match, the transition falls back to metadata.order_reference.
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 3, orderOverrides: [
            'order_reference' => 'ORDREF12345X',
            // No session id / payment intent set on this Order, so resolution
            // must reach the metadata fallback.
            'stripe_session_id' => null,
            'stripe_payment_intent_id' => null,
        ]);

        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_refund_metadata',
            type: 'charge.refunded',
            data: ['metadata' => ['order_reference' => 'ORDREF12345X']],
        ));

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    // ---- Dispute webhook idempotency ----------------------------------------

    public function test_dispute_webhook_is_idempotent_on_redelivery_returning_capacity_once(): void
    {
        // Requirement 17.5 idempotency: a redelivered charge.dispute.created
        // (same event id) is deduped, so the Order stays disputed and its
        // capacity is returned exactly once (never driven negative).
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 4, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_dispute_dupe',
        ]);

        $event = new StripeWebhookEvent(
            id: 'evt_dispute_dupe',
            type: 'charge.dispute.created',
            data: ['payment_intent' => 'pi_dispute_dupe'],
        );

        $processor = $this->processor();
        $this->assertTrue($processor->process($event), 'First delivery should process.');
        $this->assertFalse($processor->process($event), 'Redelivery should be deduped.');

        $this->assertSame(Order::STATUS_DISPUTED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
        $this->assertSame(1, ProcessedWebhook::query()->where('stripe_event_id', 'evt_dispute_dupe')->count());
    }

    // ---- Graceful handling of unresolvable / already-terminal Orders --------

    public function test_refund_webhook_for_unresolvable_order_is_recorded_without_side_effects(): void
    {
        // Requirement 17.4: a refund webhook whose identifiers match no Order is
        // still accepted and recorded (so Stripe stops retrying) but changes no
        // state — no crash, no capacity movement.
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 2, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_present',
        ]);

        $processed = $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_refund_orphan',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_does_not_exist'],
        ));

        $this->assertTrue($processed, 'The unmatched event is still claimed/recorded.');
        $this->assertDatabaseHas('processed_webhooks', ['stripe_event_id' => 'evt_refund_orphan']);

        // The unrelated paid Order is untouched: status, tickets, capacity.
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(2, $type->fresh()->sold_count);
        $this->assertSame(
            2,
            Ticket::withoutGlobalScopes()->where('order_id', $order->getKey())->where('status', Ticket::STATUS_VALID)->count(),
        );
    }

    public function test_refund_webhook_for_already_disputed_order_is_a_noop(): void
    {
        // Requirements 17.4, 17.5: if a later charge.refunded arrives for an
        // Order already terminal (disputed), the transition is skipped — the
        // status stays disputed and capacity is not returned a second time.
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 2, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_already_disputed',
        ]);

        // First: the dispute transition (paid → disputed, capacity returned).
        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_dispute_first',
            type: 'charge.dispute.created',
            data: ['payment_intent' => 'pi_already_disputed'],
        ));

        $this->assertSame(Order::STATUS_DISPUTED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->sold_count);

        // Then: a refund webhook for the same charge (distinct event id, so not
        // deduped by id) — but the Order is already terminal, so it is a no-op.
        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_refund_after_dispute',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_already_disputed'],
        ));

        // Disputed status preserved (not overwritten to refunded); capacity not
        // driven negative; no Stripe refund from the webhook path.
        $this->assertSame(Order::STATUS_DISPUTED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
        $this->assertCount(0, $this->fakeStripe()->refundCalls);
    }

    // ---- Convergence of the two terminal webhooks ---------------------------

    public function test_dispute_then_refund_webhooks_converge_first_transition_wins(): void
    {
        // Requirements 17.4, 17.5: dispute first, then refund — the first
        // terminal transition wins and the second is a no-op on the shared path.
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 2, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_converge_dr',
        ]);

        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_conv_dispute',
            type: 'charge.dispute.created',
            data: ['payment_intent' => 'pi_converge_dr'],
        ));
        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_conv_refund',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_converge_dr'],
        ));

        $this->assertSame(Order::STATUS_DISPUTED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    public function test_refund_then_dispute_webhooks_converge_first_transition_wins(): void
    {
        // The reverse ordering also converges: refund first, then a dispute
        // webhook for the same charge is a no-op — the Order stays refunded.
        $company = Company::factory()->create();
        [$order, $type] = $this->paidOrder($company, qty: 2, orderOverrides: [
            'stripe_payment_intent_id' => 'pi_converge_rd',
        ]);

        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_conv_refund_first',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_converge_rd'],
        ));
        $this->processor()->process(new StripeWebhookEvent(
            id: 'evt_conv_dispute_second',
            type: 'charge.dispute.created',
            data: ['payment_intent' => 'pi_converge_rd'],
        ));

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertAllTicketsVoided($order);
        $this->assertSame(0, $type->fresh()->sold_count);
    }
}
