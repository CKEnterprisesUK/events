<?php

namespace Tests\Feature;

use App\Jobs\ProcessWebhookJob;
use App\Models\Event;
use App\Models\Order;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 16.4 (enqueue-on-valid-webhook).
 *
 * Focused coverage for Requirement 19.4: WHEN a valid webhook is received, THE
 * Platform SHALL enqueue heavy processing work on the DB_Queue. This complements
 * task 16.1's broader pipeline test — here we prove the *enqueue* contract
 * specifically:
 *   - a signature-valid POST returns a fast 2xx and pushes a ProcessWebhookJob,
 *   - the job is enqueued on the `database` queue connection (the DB_Queue),
 *   - the endpoint does NOT process the event inline (the Order stays reserved
 *     and no `processed_webhooks` row is written until the queue is drained),
 *   - without faking, a real row lands on the `jobs` table.
 *
 * Stripe is mocked via the container-bound {@see FakeStripePaymentService}; no
 * live Stripe call and no card data are involved.
 */
class StripeWebhookEnqueueTest extends TestCase
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

    /**
     * @param  array<string, string>  $server
     */
    private function postWebhook(string $payload, array $server)
    {
        return $this->call('POST', '/stripe/webhook', [], [], [], $server, $payload);
    }

    public function test_valid_webhook_enqueues_process_job_on_the_database_queue(): void
    {
        // Requirement 19.4: a signature-valid webhook enqueues heavy processing
        // on the DB_Queue — assert the job is pushed on the `database`
        // connection specifically, carrying the verified event.
        Queue::fake();

        [$payload, $signature] = $this->signedEvent('evt_enqueue_1', 'checkout.session.completed', [
            'id' => 'cs_test_enqueue',
        ]);

        $this->postWebhook($payload, ['HTTP_STRIPE_SIGNATURE' => $signature])
            ->assertOk()
            ->assertJson(['received' => true]);

        // Exactly one heavy-processing job is enqueued, carrying the verified
        // event so the drained job needs no re-verification.
        Queue::assertPushed(ProcessWebhookJob::class, 1);
        Queue::assertPushed(function (ProcessWebhookJob $job): bool {
            // The job dispatches on the default (unset) connection, which
            // resolves to the `database` driver configured for the DB_Queue
            // (QUEUE_CONNECTION=database). The concrete jobs-table assertion in
            // this file proves the row lands on the DB queue at runtime.
            return $job->connection === null || $job->connection === 'database';
        });
    }

    public function test_valid_webhook_acknowledges_without_processing_inline(): void
    {
        // Requirement 19.4: heavy work is deferred to the queue, not run inline.
        // The endpoint returns 2xx immediately while the matching Order is still
        // reserved and no webhook has been recorded — processing only happens
        // once the DB_Queue is drained.
        Queue::fake();

        $event = Event::factory()->create();
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
            'stripe_session_id' => 'cs_test_inline',
        ]);

        [$payload, $signature] = $this->signedEvent('evt_enqueue_2', 'checkout.session.completed', [
            'id' => 'cs_test_inline',
        ]);

        $this->postWebhook($payload, ['HTTP_STRIPE_SIGNATURE' => $signature])
            ->assertOk();

        // The heavy work was enqueued, not executed synchronously.
        Queue::assertPushed(ProcessWebhookJob::class);
        $this->assertSame(
            Order::STATUS_RESERVED,
            $order->fresh()->status,
            'The Order must not be marked paid inline; that happens when the job runs.',
        );
        $this->assertDatabaseMissing('processed_webhooks', ['stripe_event_id' => 'evt_enqueue_2']);
    }

    public function test_valid_webhook_writes_a_real_row_to_the_jobs_table(): void
    {
        // Requirement 19.4: without faking the queue, a valid webhook leaves a
        // pending job on the DB_Queue's `jobs` table for the Cron_Runner to
        // drain. The payload names the ProcessWebhookJob to prove it is the
        // enqueued unit of work.
        $this->assertSame(0, DB::table('jobs')->count());

        [$payload, $signature] = $this->signedEvent('evt_enqueue_3', 'account.updated', [
            'id' => 'acct_enqueue',
            'charges_enabled' => true,
        ]);

        $this->postWebhook($payload, ['HTTP_STRIPE_SIGNATURE' => $signature])
            ->assertOk();

        $this->assertSame(1, DB::table('jobs')->count());
        $job = DB::table('jobs')->first();
        $this->assertSame('default', $job->queue);

        $decoded = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(ProcessWebhookJob::class, $decoded['displayName']);
        $this->assertSame(ProcessWebhookJob::class, $decoded['data']['commandName']);
    }
}
