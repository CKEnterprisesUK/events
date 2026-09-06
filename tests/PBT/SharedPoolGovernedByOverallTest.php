<?php

namespace Tests\PBT;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for shared-pool capacity governance (design Property 3).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the {@see CapacityReservationService}'s
 * `SELECT ... FOR UPDATE` row locking and count arithmetic behave exactly as in
 * production.
 *
 * Property 3 — Shared-pool type is governed solely by the overall ceiling:
 *   For an Event with a non-null overall capacity `C` and a shared_pool
 *   Ticket_Type (`capacity_mode = shared_pool`, `capacity = null`), a
 *   reservation of quantity `q` against that type is admitted IF AND ONLY IF
 *   `q <=` the Event's overall REMAINING capacity `r = C - sum(sold+reserved)`
 *   across ALL of the Event's types. No separate per-type ceiling is applied to
 *   the shared-pool type (it has none — `capacity` is null). When admitted, the
 *   shared-pool type's own `reserved_count` increases by exactly `q` (its counts
 *   are still tracked). When rejected, an {@see InsufficientCapacityException}
 *   is raised and every count is left byte-for-byte unchanged.
 *
 *   Overall remaining is varied per iteration by optionally seeding a second
 *   capped "consumer" type with some pre-committed sold/reserved units, so the
 *   pool the shared-pool type draws from is not simply the full capacity.
 *
 * **Validates: Requirements 2.3, 2.6, 7.2**
 */
class SharedPoolGovernedByOverallTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    /**
     * Property 3: Shared-pool type is governed solely by the overall ceiling
     * (no per-type ceiling applied; own counts still tracked).
     *
     * **Validates: Requirements 2.3, 2.6, 7.2**
     */
    // Feature: event-experience-polish, Property 3: Shared-pool type is governed solely by the overall ceiling
    public function test_shared_pool_type_is_governed_solely_by_the_overall_ceiling(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Overall event capacity C (3..20).
            Generator\choose(3, 20),
            // Pre-committed units on a second capped consumer type, split into
            // sold + reserved, so the overall remaining pool varies. Kept small;
            // the body clamps the total so it never exceeds C, and the consumer
            // type's own capacity is set large enough to hold its seed.
            Generator\choose(0, 12),
            Generator\choose(0, 12),
            // The quantity requested for the shared-pool type (1..25) — spans
            // both q <= remaining (admitted) and q > remaining (rejected).
            Generator\choose(1, 25),
        )
            ->then(function (int $capacity, int $seedSold, int $seedReserved, int $requestQty): void {
                // Each iteration builds its OWN Event + types and reasons only
                // about those rows, so no cross-iteration cleanup is needed.
                $event = Event::factory()->create(['capacity' => $capacity]);

                // The consumer's pre-committed total must fit within the event
                // capacity so the starting state is valid (never oversold).
                $consumerCommitted = min($seedSold + $seedReserved, $capacity);
                // Re-split the clamped total back into sold + reserved.
                $consumerSold = min($seedSold, $consumerCommitted);
                $consumerReserved = $consumerCommitted - $consumerSold;

                // Capped consumer type: capacity large enough to hold its seed
                // (its own per-type ceiling is irrelevant to this property; it
                // exists purely to consume overall capacity).
                $consumer = TicketType::factory()->forEvent($event)->create([
                    'capacity' => $capacity,
                    'capacity_mode' => TicketType::MODE_CAPPED,
                    'sold_count' => $consumerSold,
                    'reserved_count' => $consumerReserved,
                ]);

                // The shared-pool type under test: no per-type capacity.
                $pool = TicketType::factory()->forEvent($event)->sharedPool()->create([
                    'sold_count' => 0,
                    'reserved_count' => 0,
                ]);

                // Sanity: the factory state produced a genuine shared-pool type.
                $this->assertTrue($pool->isSharedPool());
                $this->assertNull($pool->capacity);

                // Overall remaining across ALL types before the operation.
                $remaining = $capacity - $consumerCommitted;

                $before = $this->countsSnapshot([$consumer, $pool]);
                $service = $this->service();

                $admitted = $remaining >= $requestQty;

                if ($admitted) {
                    // q <= r: the reserve must succeed, and the shared-pool
                    // type's own reserved_count must increase by exactly q while
                    // the consumer's counts are untouched.
                    $service->reserve($event, [$pool->id => $requestQty]);

                    $after = $this->countsSnapshot([$consumer, $pool]);

                    $this->assertSame(
                        $before[$pool->id]['reserved'] + $requestQty,
                        $after[$pool->id]['reserved'],
                        'Shared-pool reserved_count must increase by exactly the requested quantity.'
                    );
                    $this->assertSame(
                        $before[$pool->id]['sold'],
                        $after[$pool->id]['sold'],
                        'A reserve must not touch the shared-pool sold_count.'
                    );
                    $this->assertSame(
                        $before[$consumer->id],
                        $after[$consumer->id],
                        'Reserving the shared-pool type must not touch the consumer type.'
                    );

                    // No per-type ceiling: the shared-pool type has a null
                    // capacity, so it cannot have a per-type bound. The fact
                    // that this reserve was admitted purely because q <= the
                    // overall remaining — even when q is large — demonstrates
                    // the overall ceiling is the SOLE governor.
                    $this->assertNull(
                        $pool->fresh()->capacity,
                        'A shared-pool type must never gain a per-type capacity.'
                    );
                } else {
                    // q > r: the reserve must be rejected in full — exception
                    // raised and every count left unchanged (atomic).
                    try {
                        $service->reserve($event, [$pool->id => $requestQty]);
                        $this->fail(sprintf(
                            'Expected InsufficientCapacityException: requested %d against overall remaining %d.',
                            $requestQty,
                            $remaining,
                        ));
                    } catch (InsufficientCapacityException $e) {
                        $this->assertSame(
                            $before,
                            $this->countsSnapshot([$consumer, $pool]),
                            'A rejected reserve must leave every count unchanged (atomic).'
                        );
                    }
                }
            });
    }

    /**
     * A snapshot of every type's persisted (sold_count, reserved_count), keyed
     * by id, read scope-free so it reflects the committed database state.
     *
     * @param  list<TicketType>  $types
     * @return array<int, array{sold: int, reserved: int}>
     */
    private function countsSnapshot(array $types): array
    {
        $ids = array_map(static fn (TicketType $t): int => $t->id, $types);

        $rows = TicketType::withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get(['id', 'sold_count', 'reserved_count']);

        $snapshot = [];
        foreach ($rows as $row) {
            $snapshot[$row->id] = [
                'sold' => (int) $row->sold_count,
                'reserved' => (int) $row->reserved_count,
            ];
        }

        return $snapshot;
    }
}
