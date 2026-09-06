<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\OrderFulfilmentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 18.3.
 *
 * Focused feature test for enqueue-on-confirm of the ticket email: confirming
 * an Order enqueues {@see SendTicketEmailJob} on the DB queue exactly once, and
 * an idempotent re-confirm / webhook redelivery never enqueues it again. Both
 * confirmation paths are covered — a free Order confirmed at checkout and a
 * paid Order confirmed by the `checkout.session.completed` webhook.
 *
 * Requirement 14.3.
 *
 * The job is asserted with {@see Queue::fake()} / {@see Queue::assertPushed()},
 * so no worker runs and no email is ever really sent. Runs against the real
 * MySQL test DB so the FOR UPDATE capacity commit behaves as in production.
 */
class TicketEmailEnqueuedOnConfirmTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A confirmed Order (defaults to free_confirmed) holding $qty reserved
     * tickets of one Ticket_Type, ready to be fulfilled.
     *
     * @return array{0: Order, 1: TicketType}
     */
    private function confirmedOrder(int $qty = 2, string $status = Order::STATUS_FREE_CONFIRMED): array
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => $qty,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => $status,
            'fulfilled_at' => null,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type];
    }

    public function test_the_ticket_email_job_is_queued_not_run_synchronously(): void
    {
        // Requirement 14.3: the ticket email is a queued job, so it is enqueued
        // on confirmation and sent later when the DB queue drains.
        $this->assertInstanceOf(ShouldQueue::class, app(SendTicketEmailJob::class, [
            'orderId' => 1,
            'qrPayload' => 'payload',
        ]));
    }

    public function test_confirming_an_order_enqueues_the_ticket_email_on_the_database_queue(): void
    {
        Queue::fake();

        [$order] = $this->confirmedOrder(qty: 2);

        app(OrderFulfilmentService::class)->fulfil($order);

        // Enqueued exactly once, and onto the database queue connection. (14.3)
        Queue::assertPushedOn(null, SendTicketEmailJob::class);
        Queue::assertPushed(SendTicketEmailJob::class, 1);
        Queue::assertPushed(SendTicketEmailJob::class, function (SendTicketEmailJob $job): bool {
            return $job->connection === null || $job->connection === 'database';
        });
    }

    public function test_idempotent_reconfirm_does_not_enqueue_the_ticket_email_again(): void
    {
        Queue::fake();

        [$order] = $this->confirmedOrder(qty: 2);
        $service = app(OrderFulfilmentService::class);

        $this->assertTrue($service->fulfil($order), 'First confirmation should enqueue.');
        $this->assertFalse($service->fulfil($order->fresh()), 'Re-confirm should be a no-op.');
        $this->assertFalse($service->fulfil($order->fresh()), 'Re-confirm should be a no-op.');

        // Still exactly one job across the repeated confirmations. (14.3)
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    public function test_free_order_confirmed_at_checkout_enqueues_the_ticket_email(): void
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
        $this->assertSame(Order::STATUS_FREE_CONFIRMED, $order->status);

        // Confirmed at checkout ⇒ the ticket email is enqueued once. (14.3)
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }

    public function test_paid_order_confirmed_by_the_webhook_enqueues_the_ticket_email(): void
    {
        Queue::fake();

        [$order] = $this->confirmedOrder(qty: 2, status: Order::STATUS_RESERVED);
        $order->stripe_session_id = 'cs_test_enqueue_on_confirm';
        $order->save();

        $event = new StripeWebhookEvent(
            id: 'evt_enqueue_on_confirm',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_test_enqueue_on_confirm'],
        );

        $processor = app(WebhookProcessor::class);
        $this->assertTrue($processor->process($event));

        // Paid confirmation via the webhook enqueues the ticket email once. (14.3)
        Queue::assertPushed(SendTicketEmailJob::class, 1);

        // Redelivery of the same event is deduped ⇒ no second enqueue. (14.3)
        $this->assertFalse($processor->process($event));
        Queue::assertPushed(SendTicketEmailJob::class, 1);
    }
}
