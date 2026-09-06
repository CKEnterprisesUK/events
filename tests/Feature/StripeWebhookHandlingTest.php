<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Models\Ticket;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 16.1.
 *
 * Exercises the Stripe webhook pipeline end to end with Stripe mocked via the
 * container-bound {@see FakeStripePaymentService}: signature verification
 * (accept valid / reject invalid+absent, Requirements 19.1, 19.2), enqueue of
 * heavy work on the DB queue (Requirement 19.4), idempotent processing keyed on
 * the Stripe event id (Requirements 12.6, 12.7, 19.3), and the
 * `checkout.session.completed` handler marking the matching reserved Order paid
 * exactly once (Requirement 12.6). No live Stripe call and no card data are
 * involved.
 */
class StripeWebhookHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * Build a raw webhook payload and its matching valid signature header.
     *
     * @param  array<string, mixed>  $object
     * @return array{0: string, 1: string} [payload, signature]
     */
    private function signedEvent(string $id, string $type, array $object = []): array
    {
        $payload = json_encode([
            'id' => $id,
            'type' => $type,
            'data' => ['object' => $object],
        ], JSON_THROW_ON_ERROR);

        return [$payload, $this->fakeStripe()->signPayload($payload)];
    }

    public function test_valid_signature_is_accepted_and_enqueues_processing(): void
    {
        // Requirements 19.1, 19.4: a signature-valid webhook is accepted and
        // heavy processing is enqueued on the DB queue.
        Queue::fake();

        [$payload, $signature] = $this->signedEvent('evt_valid_1', 'checkout.session.completed', [
            'id' => 'cs_test_abc',
        ]);

        $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload,
        )->assertOk()->assertJson(['received' => true]);

        Queue::assertPushed(ProcessWebhookJob::class);
    }

    public function test_invalid_signature_is_rejected_with_no_state_change(): void
    {
        // Requirement 19.2: an invalid signature is rejected with an error and
        // nothing is enqueued or recorded.
        Queue::fake();

        [$payload] = $this->signedEvent('evt_bad', 'checkout.session.completed');

        $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => 'clearly-not-a-valid-signature'],
            $payload,
        )->assertStatus(400);

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('processed_webhooks', 0);
    }

    public function test_missing_signature_is_rejected(): void
    {
        // Requirement 19.2: an absent signature header is rejected as well.
        Queue::fake();

        [$payload] = $this->signedEvent('evt_missing', 'checkout.session.completed');

        $this->call('POST', '/stripe/webhook', [], [], [], [], $payload)
            ->assertStatus(400);

        Queue::assertNothingPushed();
    }

    public function test_checkout_session_completed_marks_matching_reserved_order_paid(): void
    {
        // Requirement 12.6: checkout.session.completed marks the matching
        // reserved Order paid.
        $event = Event::factory()->create();
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
            'stripe_session_id' => 'cs_test_paidflow',
        ]);

        [$payload, $signature] = $this->signedEvent('evt_paid_1', 'checkout.session.completed', [
            'id' => 'cs_test_paidflow',
        ]);

        $this->call(
            'POST',
            '/stripe/webhook',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload,
        )->assertOk();

        // Drain the DB queue synchronously so the enqueued job runs.
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertExitCode(0);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertDatabaseHas('processed_webhooks', [
            'stripe_event_id' => 'evt_paid_1',
            'type' => 'checkout.session.completed',
        ]);
    }

    public function test_duplicate_event_id_is_processed_at_most_once(): void
    {
        // Requirements 12.7, 19.3: a redelivered event id is processed once —
        // the Order is marked paid a single time and no extra work occurs.
        $event = Event::factory()->create();
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
            'stripe_session_id' => 'cs_test_dupe',
        ]);

        $stripeEvent = new StripeWebhookEvent(
            id: 'evt_dupe_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_test_dupe'],
        );

        /** @var WebhookProcessor $processor */
        $processor = app(WebhookProcessor::class);

        // First delivery processes and marks paid.
        $this->assertTrue($processor->process($stripeEvent), 'First delivery should process.');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        // Reset the Order to reserved directly in the DB (bypassing the tenant
        // scope, which is not active on this webhook path) so we can prove a
        // redelivery does NOT reprocess it back to paid.
        Order::withoutGlobalScopes()->whereKey($order->getKey())
            ->update(['status' => Order::STATUS_RESERVED]);

        // Redelivery of the same event id is a no-op (deduped on the event id).
        $this->assertFalse($processor->process($stripeEvent), 'Redelivery should be deduped.');
        $this->assertSame(
            Order::STATUS_RESERVED,
            Order::withoutGlobalScopes()->find($order->getKey())->status,
            'A duplicate event id must not be processed again.',
        );

        // The event id was recorded exactly once.
        $this->assertSame(1, ProcessedWebhook::query()->where('stripe_event_id', 'evt_dupe_1')->count());
    }

    public function test_already_paid_order_is_left_unchanged_on_redelivery(): void
    {
        // Requirement 12.7: a checkout.session.completed for an already-paid
        // Order leaves it unchanged (no additional charge / status change).
        $event = Event::factory()->create();
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'stripe_session_id' => 'cs_test_alreadypaid',
        ]);

        $stripeEvent = new StripeWebhookEvent(
            id: 'evt_alreadypaid',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_test_alreadypaid'],
        );

        app(WebhookProcessor::class)->process($stripeEvent);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_refund_webhook_voids_tickets(): void
    {
        // Requirement 17.4: a refund webhook marks the Order refunded and voids
        // its Tickets so the QR fails at scan.
        $event = Event::factory()->create();
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'stripe_payment_intent_id' => 'pi_refundme',
        ]);
        Ticket::factory()->forOrder($order)->count(2)->create();

        $stripeEvent = new StripeWebhookEvent(
            id: 'evt_refund_1',
            type: 'charge.refunded',
            data: ['payment_intent' => 'pi_refundme'],
        );

        app(WebhookProcessor::class)->process($stripeEvent);

        $this->assertSame(Order::STATUS_REFUNDED, $order->fresh()->status);
        $this->assertSame(
            0,
            Ticket::withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->where('status', Ticket::STATUS_VALID)
                ->count(),
            'All Tickets should be voided after a refund webhook.',
        );
    }

    public function test_account_capability_update_webhook_enables_charges(): void
    {
        // Requirement 11.4: an account.updated capability webhook refreshes the
        // Company's charges-enabled flag.
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_HOOK',
            'stripe_charges_enabled' => false,
        ]);

        $stripeEvent = new StripeWebhookEvent(
            id: 'evt_acct_1',
            type: 'account.updated',
            data: ['id' => 'acct_HOOK', 'charges_enabled' => true],
        );

        app(WebhookProcessor::class)->process($stripeEvent);

        $this->assertTrue($company->fresh()->stripe_charges_enabled);
    }
}
