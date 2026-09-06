<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use Eris\Generator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for Order_Reference uniqueness (Property 14).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the `orders.order_reference` UNIQUE index is exercised
 * exactly as in production.
 *
 * The invariant, per Requirement 10.13: every Order is assigned an
 * Order_Reference that is unique across the entire Platform — across any number
 * of Companies and their Events. Orders here are created through the Order
 * factory, which assigns the reference with the *same* generator the real
 * checkout path uses (`strtoupper(Str::random(12))`), so the values under test
 * come from the production creation path's reference source.
 *
 * Each iteration:
 * - spreads a random total number of Orders across a random number of Companies
 *   (each with its own Event), then
 * - asserts every assigned Order_Reference is distinct (no collisions),
 * - asserts each matches the expected format (12 uppercase alphanumerics), and
 * - asserts the DB UNIQUE constraint actually rejects a duplicate reference
 *   (persisting an existing reference again throws a query exception).
 */
class OrderReferenceUniquenessTest extends PbtTestCase
{
    use RefreshDatabase;

    /** The Order_Reference shape produced by the generator: 12 uppercase alphanumerics. */
    private const REFERENCE_PATTERN = '/^[A-Z0-9]{12}$/';

    /**
     * Property 14: Order_Reference uniqueness — all assigned Order_Reference
     * values are distinct across any Companies. Every reference matches the
     * expected format, and the Platform-wide UNIQUE constraint rejects any
     * attempt to persist a duplicate reference.
     *
     * **Validates: Requirements 10.13**
     */
    // Feature: event-ticketing-platform, Property 14: Order_Reference uniqueness — all assigned Order_Reference values are distinct across any Companies
    public function test_order_references_are_unique_across_any_companies(): void
    {
        $this->forAll(
            // 1–4 Companies, each with its own Event, so references must stay
            // unique across Company boundaries (not merely within one Company).
            Generator\choose(1, 4),
            // 2–8 Orders spread across those Companies per iteration; kept modest
            // so >=100 iterations run against real MySQL in reasonable time.
            Generator\choose(2, 8),
        )
            ->then(function (int $companyCount, int $orderCount): void {
                // Fresh set of Companies (each with an Event) for this iteration.
                // RefreshDatabase rolls each iteration's rows back afterwards; no
                // DDL / TRUNCATE is used here.
                $events = [];
                for ($i = 0; $i < $companyCount; $i++) {
                    $company = Company::factory()->create();
                    $events[] = Event::factory()->for($company)->create();
                }

                // Create the Orders, distributing them round-robin across the
                // Companies' Events. The factory assigns each Order a reference
                // via the same generator the checkout path uses.
                $orders = [];
                for ($n = 0; $n < $orderCount; $n++) {
                    $event = $events[$n % count($events)];
                    $orders[] = Order::factory()->forEvent($event)->create();
                }

                $references = array_map(
                    static fn (Order $order): string => $order->order_reference,
                    $orders
                );

                // Every reference matches the expected Platform format.
                foreach ($references as $reference) {
                    $this->assertMatchesRegularExpression(
                        self::REFERENCE_PATTERN,
                        $reference,
                        "Order_Reference '{$reference}' must be 12 uppercase alphanumerics."
                    );
                }

                // No collisions: the assigned references are all distinct across
                // every Company in this iteration. (Requirement 10.13)
                $this->assertSameSize(
                    $references,
                    array_unique($references),
                    'All assigned Order_References must be distinct across Companies.'
                );

                // The DB UNIQUE constraint must reject a duplicate reference:
                // re-persisting an existing reference (on any Company/Event) is
                // rejected at the storage layer, which is what guarantees
                // Platform-wide uniqueness under concurrency. The exception is
                // asserted inline (not via expectException) so every Eris
                // iteration validates it rather than ending the property loop.
                $existing = $orders[0];

                $duplicateRejected = false;
                try {
                    Order::factory()->forEvent($events[0])->create([
                        'order_reference' => $existing->order_reference,
                    ]);
                } catch (QueryException $e) {
                    $duplicateRejected = true;
                }

                $this->assertTrue(
                    $duplicateRejected,
                    "Persisting a duplicate Order_Reference '{$existing->order_reference}' must be rejected by the UNIQUE constraint."
                );
            });
    }
}
