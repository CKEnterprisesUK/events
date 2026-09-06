<?php

namespace Tests\PBT;

use App\Jobs\ProcessWebhookJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Property-based test for webhook idempotency (Property 21).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database so the `processed_webhooks` UNIQUE(`stripe_event_id`) claim and
 * the `checkout.session.completed` `SELECT ... FOR UPDATE` order lock behave
 * exactly as in production.
 *
 * The property (task 16.3): processing the SAME verified Stripe event id any
 * number of times — an original delivery, Stripe redeliveries, and job retries —
 * has the SAME effect as processing it exactly once. So a reserved Order it
 * completes transitions reserved -> paid exactly once, an already-paid Order is
 * left untouched on redelivery (no double side effects / no additional charge),
 * and `processed_webhooks` holds exactly one row per distinct event id.
 * (Requirements 12.6, 12.7, 19.3)
 *
 * Generators build a batch of distinct `checkout.session.completed` events, each
 * bound to its own freshly reserved Order by `stripe_session_id`, then a random
 * replay schedule (how many extra times each event id is delivered) and a random
 * shuffled interleaving of all those deliveries. Half the iterations drive the
 * {@see WebhookProcessor} directly; the other half drive it through
 * {@see ProcessWebhookJob} (the retry-safe queue path). The final state is then
 * compared against an independent "processed exactly once" oracle order.
 */
class WebhookIdempotencyTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * Property 21: Webhook idempotency — a verified Stripe event id processed
     * any number of times (redelivery / replay / retry) has the same effect as
     * processing it once: the matching reserved Order transitions to paid
     * exactly once with no duplicate side effects, and `processed_webhooks`
     * holds exactly one row per distinct event id. (Stripe mocked)
     *
     * **Validates: Requirements 12.6, 12.7, 19.3**
     */
    // Feature: event-ticketing-platform, Property 21: Webhook idempotency — event processed at most once; checkout.session.completed marks paid exactly once and creates no additional charge on redelivery
    public function test_processing_the_same_event_id_repeatedly_equals_processing_it_once(): void
    {
        $this->forAll(
            // Number of distinct orders/events in this batch (1-4).
            Generator\choose(1, 4),
            // For each potential event, how many EXTRA redeliveries it gets
            // (0-4) on top of its original delivery. Sized to the batch below.
            Generator\seq(Generator\choose(0, 4)),
            // Drive path: 0 = call the processor directly; 1 = via the retry-safe
            // ProcessWebhookJob (both must be equally idempotent).
            Generator\choose(0, 1),
            // A shuffle seed so the interleaving of deliveries varies.
            Generator\choose(0, PHP_INT_MAX)
        )
            ->then(function (
                int $orderCount,
                array $extraDeliveries,
                int $drivePath,
                int $shuffleSeed
            ): void {
                // One shared, connected Company/Event owns every Order in the
                // batch; each Order is independently reserved.
                $company = Company::factory()->create([
                    'stripe_account_id' => 'acct_test123',
                    'stripe_charges_enabled' => true,
                ]);
                $event = Event::factory()->for($company)->published()->create();

                // Build the distinct events, each bound to its own reserved Order
                // by a unique Stripe Checkout Session id.
                $orders = [];
                $events = [];
                for ($i = 0; $i < $orderCount; $i++) {
                    $sessionId = 'cs_test_'.Str::random(20);
                    $order = Order::factory()->forEvent($event)->create([
                        'status' => Order::STATUS_RESERVED,
                        'stripe_session_id' => $sessionId,
                    ]);

                    $orders[] = $order;
                    $events[] = new StripeWebhookEvent(
                        id: 'evt_test_'.Str::random(20),
                        type: 'checkout.session.completed',
                        data: ['id' => $sessionId],
                    );
                }

                // Build the full delivery list: each event delivered once plus a
                // random number of extra redeliveries, then shuffled so the
                // duplicates land in an arbitrary interleaving.
                $deliveries = [];
                foreach ($events as $i => $stripeEvent) {
                    $extra = $extraDeliveries[$i] ?? 0;
                    $times = 1 + max(0, $extra);
                    for ($t = 0; $t < $times; $t++) {
                        $deliveries[] = $stripeEvent;
                    }
                }

                mt_srand($shuffleSeed);
                $this->shuffleDeterministic($deliveries);

                // Deliver every event (originals + replays) through the chosen
                // path. This is the system under test.
                foreach ($deliveries as $stripeEvent) {
                    if ($drivePath === 0) {
                        app(WebhookProcessor::class)->process($stripeEvent);
                    } else {
                        (new ProcessWebhookJob($stripeEvent))->handle(app(WebhookProcessor::class));
                    }
                }

                // Oracle: an independent set of orders processed EXACTLY ONCE
                // each, so we can compare "any number of times" against "once".
                $expectedStatusById = [];
                foreach ($orders as $order) {
                    $expectedStatusById[$order->getKey()] = Order::STATUS_PAID;
                }

                // 1) Every reserved Order transitioned to paid exactly once —
                //    the final state equals the processed-once state. A repeated
                //    or replayed delivery never overshoots paid or reverts it.
                foreach ($orders as $order) {
                    $this->assertSame(
                        $expectedStatusById[$order->getKey()],
                        Order::withoutGlobalScopes()->findOrFail($order->getKey())->status,
                        'Each matching reserved Order must end paid exactly as if processed once.'
                    );
                }

                // 2) Exactly one processed_webhooks row per DISTINCT event id, no
                //    matter how many times each id was delivered.
                foreach ($events as $stripeEvent) {
                    $this->assertSame(
                        1,
                        ProcessedWebhook::query()
                            ->where('stripe_event_id', $stripeEvent->id)
                            ->count(),
                        'A replayed event id must record exactly one processed_webhooks row.'
                    );
                }

                // 3) The total number of processed_webhooks rows equals the
                //    number of DISTINCT event ids — no duplicate claims survived.
                $this->assertSame(
                    count($events),
                    ProcessedWebhook::query()
                        ->whereIn('stripe_event_id', array_map(fn ($e) => $e->id, $events))
                        ->count(),
                    'processed_webhooks holds one row per distinct event id — no duplicates.'
                );

                // 4) No Order is paid twice / no double fulfilment: re-processing
                //    an ALREADY-paid Order (a further redelivery after paid) is a
                //    no-op — the status stays paid and the claim stays deduped.
                foreach ($events as $i => $stripeEvent) {
                    $before = ProcessedWebhook::query()
                        ->where('stripe_event_id', $stripeEvent->id)->count();

                    // Deliver the same id one more time now that its Order is paid.
                    if ($drivePath === 0) {
                        app(WebhookProcessor::class)->process($stripeEvent);
                    } else {
                        (new ProcessWebhookJob($stripeEvent))->handle(app(WebhookProcessor::class));
                    }

                    $this->assertSame(
                        Order::STATUS_PAID,
                        Order::withoutGlobalScopes()->findOrFail($orders[$i]->getKey())->status,
                        'Redelivering an already-paid Order leaves it paid — never paid twice.'
                    );
                    $this->assertSame(
                        $before,
                        ProcessedWebhook::query()
                            ->where('stripe_event_id', $stripeEvent->id)->count(),
                        'Redelivery after paid adds no additional processed_webhooks row.'
                    );
                }

                // Reset the per-iteration rows (RefreshDatabase wraps the whole
                // test in a transaction; deletes keep iterations independent
                // without any DDL / TRUNCATE).
                ProcessedWebhook::query()->delete();
                Order::withoutGlobalScopes()->whereIn('id', array_map(fn ($o) => $o->getKey(), $orders))->delete();
                DB::table('events')->where('id', $event->id)->delete();
                DB::table('companies')->where('id', $company->id)->delete();
            });
    }

    /**
     * Deterministic Fisher-Yates shuffle driven by the seeded mt_rand, so the
     * interleaving of duplicate deliveries is reproducible per iteration.
     *
     * @param  array<int, StripeWebhookEvent>  $items
     */
    private function shuffleDeterministic(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }
}
