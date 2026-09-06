<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Mail\FakeTicketMailer;
use App\Services\Mail\TicketMailer;
use App\Services\OrderFulfilmentService;
use App\Services\QrService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 18.1.
 *
 * Covers OrderFulfilmentService, QrService, the TicketMailer abstraction, and
 * SendTicketEmailJob: confirming an Order generates exactly one QR encoding
 * HMAC(secret, Order_Reference), commits its held capacity from reserved to
 * sold, and enqueues the branded ticket email on the DB queue; fulfilment is
 * idempotent; the free checkout path fulfils at checkout and the paid path is
 * fulfilled from the webhook.
 *
 * Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6.
 *
 * Runs against the real MySQL test DB so the FOR UPDATE capacity commit behaves
 * as in production. Mail is never really sent — the container binds the
 * {@see FakeTicketMailer} in the testing environment.
 */
class OrderFulfilmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A confirmed Order (defaults to free_confirmed) with $qty reserved tickets
     * of a single Ticket_Type, plus the Ticket_Type with the hold recorded.
     *
     * @return array{0: Order, 1: TicketType, 2: Event}
     */
    private function confirmedOrder(int $qty = 2, string $status = Order::STATUS_FREE_CONFIRMED, array $typeOverrides = []): array
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create(array_merge([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => $qty,
        ], $typeOverrides));

        $order = Order::factory()->forEvent($event)->create([
            'status' => $status,
            'fulfilled_at' => null,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type, $event];
    }

    private function fakeMailer(): FakeTicketMailer
    {
        /** @var FakeTicketMailer $mailer */
        $mailer = app(TicketMailer::class);

        return $mailer;
    }

    // ---- QrService: one QR encoding HMAC(secret, reference) -----------------

    public function test_qr_token_is_hmac_of_order_reference_and_verifies(): void
    {
        // Requirement 14.2: the QR_Token is an HMAC of the Order_Reference.
        $qr = app(QrService::class);
        $reference = 'ABC123XYZ789';

        $expected = hash_hmac('sha256', $reference, config('qr.hmac_secret'));

        $this->assertSame($expected, $qr->token($reference));
        $this->assertTrue($qr->verify($reference, $expected));

        // A tampered token or a foreign reference does not verify. (16.4, 16.5)
        $this->assertFalse($qr->verify($reference, $expected.'0'));
        $this->assertFalse($qr->verify('OTHERREFERENCE', $expected));
    }

    public function test_qr_payload_round_trips_and_rejects_tampering(): void
    {
        $qr = app(QrService::class);
        $order = Order::factory()->create(['order_reference' => 'ROUNDTRIP01']);

        $payload = $qr->payloadFor($order);

        // A valid payload decodes back to its reference. (Requirements 14.1, 16.4)
        $this->assertSame('ROUNDTRIP01', $qr->verifyPayload($payload));

        // A tampered payload verifies to null. (Requirement 16.5)
        $this->assertNull($qr->verifyPayload($payload.'tamper'));
        $this->assertNull($qr->verifyPayload('no-separator-here'));
    }

    // ---- Fulfilment: commit reserved -> sold + enqueue email ----------------

    public function test_fulfilment_commits_capacity_and_enqueues_ticket_email(): void
    {
        Queue::fake();

        [$order, $type] = $this->confirmedOrder(qty: 3);

        $result = app(OrderFulfilmentService::class)->fulfil($order);

        $this->assertTrue($result);

        // Capacity moved reserved -> sold. (Requirements 6.6, 6.7, 10.6, 14.1)
        $type->refresh();
        $this->assertSame(0, $type->reserved_count);
        $this->assertSame(3, $type->sold_count);

        // Fulfilment stamped and email enqueued on the DB queue. (14.3)
        $this->assertNotNull($order->fresh()->fulfilled_at);
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    public function test_draining_the_queue_sends_the_ticket_email_via_the_mailer(): void
    {
        // Requirement 14.4: draining the queue sends the ticket email (through
        // the mail abstraction). The fake records the send; no real email.
        [$order] = $this->confirmedOrder(qty: 2);

        app(OrderFulfilmentService::class)->fulfil($order);

        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertExitCode(0);

        $mailer = $this->fakeMailer();
        $this->assertTrue($mailer->sentFor($order->id));
        $this->assertSame(1, $mailer->countFor($order->id));

        // The email carried the QR payload encoding HMAC(reference). (14.2)
        $expectedPayload = app(QrService::class)->payloadFor($order->fresh());
        $sent = collect($mailer->sent)->firstWhere('order_id', $order->id);
        $this->assertSame($expectedPayload, $sent['qr_payload']);
    }

    public function test_fulfilment_is_idempotent_and_does_not_double_count_or_double_send(): void
    {
        Queue::fake();

        [$order, $type] = $this->confirmedOrder(qty: 2);

        $service = app(OrderFulfilmentService::class);

        $this->assertTrue($service->fulfil($order), 'First fulfilment should succeed.');
        // Re-fulfilling an already-fulfilled Order is a no-op.
        $this->assertFalse($service->fulfil($order->fresh()), 'Second fulfilment should be skipped.');
        $this->assertFalse($service->fulfil($order->fresh()), 'Third fulfilment should be skipped.');

        // sold_count committed exactly once; nothing left reserved.
        $type->refresh();
        $this->assertSame(0, $type->reserved_count);
        $this->assertSame(2, $type->sold_count);

        // Exactly one ticket email enqueued across the repeated calls. (14.3)
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    public function test_unconfirmed_reserved_order_is_not_fulfilled(): void
    {
        Queue::fake();

        [$order, $type] = $this->confirmedOrder(qty: 2, status: Order::STATUS_RESERVED);

        $this->assertFalse(app(OrderFulfilmentService::class)->fulfil($order));

        // Nothing committed, nothing enqueued, not stamped.
        $type->refresh();
        $this->assertSame(2, $type->reserved_count);
        $this->assertSame(0, $type->sold_count);
        $this->assertNull($order->fresh()->fulfilled_at);
        Queue::assertNothingPushed();
    }

    // ---- Free checkout path fulfils at checkout -----------------------------

    public function test_free_checkout_fulfils_the_order_and_enqueues_the_email(): void
    {
        Queue::fake();

        $company = Company::factory()->create([
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
        ]);
        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => 0, // free
            'capacity' => 50,
            'sold_count' => 0,
            'reserved_count' => 0,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addMonth(),
        ]);

        $this->post("/{$company->slug}/{$event->id}/checkout", [
            'customer_name' => 'Grace Hopper',
            'customer_email' => 'grace@example.test',
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 2]],
            'consents' => ['terms' => true, 'privacy' => true],
        ])->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // Free order confirmed + fulfilled at checkout. (Requirements 10.9, 14.1)
        $this->assertSame(Order::STATUS_FREE_CONFIRMED, $order->status);
        $this->assertNotNull($order->fulfilled_at);

        // Capacity committed reserved -> sold. (10.6)
        $type->refresh();
        $this->assertSame(0, $type->reserved_count);
        $this->assertSame(2, $type->sold_count);

        // Ticket email enqueued on the DB queue. (14.3)
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    // ---- Paid path fulfils from the webhook ---------------------------------

    public function test_paid_order_is_fulfilled_when_the_checkout_completed_webhook_processes(): void
    {
        Queue::fake();

        // A reserved, paid-pending Order with a held Ticket_Type. The webhook
        // will mark it paid and fulfil it.
        [$order, $type] = $this->confirmedOrder(qty: 2, status: Order::STATUS_RESERVED);
        $order->stripe_session_id = 'cs_test_fulfil';
        $order->save();

        $event = new StripeWebhookEvent(
            id: 'evt_fulfil_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_test_fulfil'],
        );

        app(WebhookProcessor::class)->process($event);

        // Marked paid, capacity committed, fulfilment stamped. (12.6, 14.1)
        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->fulfilled_at);

        $type->refresh();
        $this->assertSame(0, $type->reserved_count);
        $this->assertSame(2, $type->sold_count);

        // Ticket email enqueued exactly once. (14.3)
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    public function test_redelivered_webhook_does_not_re_fulfil_the_paid_order(): void
    {
        Queue::fake();

        [$order, $type] = $this->confirmedOrder(qty: 2, status: Order::STATUS_RESERVED);
        $order->stripe_session_id = 'cs_test_dupe_fulfil';
        $order->save();

        $event = new StripeWebhookEvent(
            id: 'evt_dupe_fulfil',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_test_dupe_fulfil'],
        );

        $processor = app(WebhookProcessor::class);
        $this->assertTrue($processor->process($event));
        // Redelivery of the same event id is deduped, so no second fulfilment.
        $this->assertFalse($processor->process($event));

        $type->refresh();
        $this->assertSame(0, $type->reserved_count);
        $this->assertSame(2, $type->sold_count);

        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }
}
