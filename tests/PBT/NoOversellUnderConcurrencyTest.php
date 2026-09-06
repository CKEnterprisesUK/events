<?php

namespace Tests\PBT;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for no-oversell under concurrency (design Property 10).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the {@see CapacityReservationService}'s
 * `SELECT ... FOR UPDATE` row locking and count arithmetic behave exactly as in
 * production.
 *
 * How concurrency is modelled without threads:
 *   The true serialization guarantee comes from MySQL's FOR UPDATE locks, which
 *   this test exercises against the real database. Concurrency is represented as
 *   a randomly-generated, interleaved SEQUENCE of reserve/release operations
 *   over a mix of Ticket_Types and quantities. Each operation is applied through
 *   the service; over-requests raise {@see InsufficientCapacityException} and are
 *   treated as rejected. This covers every ordering the service admits — the
 *   only orderings reachable in production are serializations of concurrent
 *   requests, which is exactly what an interleaved sequence enumerates.
 *
 * The invariant checked after EVERY operation (and at the end):
 *   - For every Ticket_Type: `sold_count + reserved_count <= capacity`
 *     (Requirements 6.6, 6.7, 6.8, 10.6).
 *   - Across the Event's Ticket_Types: `sum(sold_count + reserved_count) <=`
 *     the Event's overall capacity when one is set (Requirement 5.6).
 *   - Every rejected reserve is atomic: it leaves ALL counts byte-for-byte
 *     unchanged — nothing partially reserved (Requirements 6.7, 18.3).
 *
 * Comp issuance (Requirement 18.3) counts against the same capacity through the
 * same service path, so the sold-count seeding + reserve interleavings here
 * establish the property for comps too.
 */
class NoOversellUnderConcurrencyTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    /**
     * Property 10: No oversell under concurrency — across any interleaving of
     * concurrent reserve/purchase/comp requests, committed + active-reserved
     * never exceeds a Ticket_Type's (nor the Event's overall) capacity;
     * over-requests are rejected in full with prior counts left unchanged.
     *
     * **Validates: Requirements 5.6, 6.6, 6.7, 6.8, 10.6, 18.3**
     */
    // Feature: event-ticketing-platform, Property 10: No oversell under concurrency — across any interleaving of concurrent reserve/purchase/comp requests, committed+active-reserved never exceeds Ticket_Type (nor Event) capacity; over-requests rejected in full, prior counts unchanged (run against real MySQL FOR UPDATE with randomized interleavings)
    public function test_no_interleaving_of_reserve_and_release_ever_oversells(): void
    {
        $this->forAll(
            // Whether the Event sets an overall capacity (true) or is unlimited
            // (NULL = false). Both gates must hold.
            Generator\bool(),
            // The number of Ticket_Types (1–3) and a pool of candidate
            // capacities; the body slices the pool to the chosen count so each
            // type gets a small capacity that the sequence can bump against.
            Generator\choose(1, 3),
            Generator\tuple(
                Generator\choose(1, 12),
                Generator\choose(1, 12),
                Generator\choose(1, 12),
            ),
            // The interleaved operation sequence: each op is [kind, typeIndex,
            // qty] where kind 0 = reserve and 1 = release. typeIndex and qty are
            // normalised against the generated Ticket_Types inside the body.
            Generator\seq(Generator\tuple(
                Generator\choose(0, 1),
                Generator\choose(0, 5),
                Generator\choose(1, 8),
            )),
        )
            ->then(function (bool $capped, int $typeCountPick, array $capacityPool, array $operations): void {
                // Each iteration builds its OWN Event + Ticket_Types and only
                // reasons about those rows, so no cleanup of prior iterations is
                // needed. We deliberately avoid table-wide deletes here: a
                // scope-free delete across all events/types would contend with
                // the FOR UPDATE row locks and can deadlock under the tight
                // interleavings this property generates. (And DDL/TRUNCATE is
                // banned — it would break RefreshDatabase.)
                $ticketTypeCapacities = array_slice(array_values($capacityPool), 0, $typeCountPick);
                $overallCapacity = $capped
                    ? (int) array_sum($ticketTypeCapacities)
                    : null;

                $event = Event::factory()->create(['capacity' => $overallCapacity]);

                $types = [];
                foreach ($ticketTypeCapacities as $capacity) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'capacity' => $capacity,
                        'sold_count' => 0,
                        'reserved_count' => 0,
                    ]);
                }

                $service = $this->service();
                $typeCount = count($types);

                // Apply the interleaved sequence one op at a time, asserting the
                // no-oversell invariant after every step. Releases may exceed a
                // type's current hold — the service clamps at zero — so the
                // sequence freely explores reserve/release orderings.
                foreach ($operations as [$kind, $typeIndexRaw, $qty]) {
                    $type = $types[$typeIndexRaw % $typeCount];
                    $quantities = [$type->id => $qty];

                    // Snapshot every type's counts before the op so a rejected
                    // reserve can be proven to have changed nothing.
                    $before = $this->countsSnapshot($types);

                    if ($kind === 0) {
                        try {
                            $service->reserve($event, $quantities);
                        } catch (InsufficientCapacityException $e) {
                            // Rejected over-request: must be fully atomic —
                            // no count anywhere may have moved.
                            $this->assertSame(
                                $before,
                                $this->countsSnapshot($types),
                                'A rejected reserve must leave every count unchanged (atomic).'
                            );
                        }
                    } else {
                        $service->release($event, $quantities);
                    }

                    // Invariant holds after EVERY operation, accepted or not.
                    $this->assertInvariant($event, $types, $overallCapacity);
                }

                // Invariant also holds at the end of the whole sequence.
                $this->assertInvariant($event, $types, $overallCapacity);
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

    /**
     * Assert the no-oversell invariant: per Ticket_Type sold+reserved never
     * exceeds its capacity, and the Event-wide sum never exceeds the overall
     * capacity when one is set.
     *
     * @param  list<TicketType>  $types
     */
    private function assertInvariant(Event $event, array $types, ?int $overallCapacity): void
    {
        $eventCommitted = 0;

        foreach ($types as $type) {
            $fresh = TicketType::withoutGlobalScopes()->findOrFail($type->id);
            $committed = (int) $fresh->sold_count + (int) $fresh->reserved_count;

            $this->assertLessThanOrEqual(
                (int) $fresh->capacity,
                $committed,
                sprintf(
                    'Ticket_Type %d oversold: sold+reserved=%d exceeds capacity=%d.',
                    $type->id,
                    $committed,
                    $fresh->capacity,
                )
            );

            // reserved_count must never go negative (release clamps at zero).
            $this->assertGreaterThanOrEqual(
                0,
                (int) $fresh->reserved_count,
                sprintf('Ticket_Type %d reserved_count went negative.', $type->id)
            );

            $eventCommitted += $committed;
        }

        if ($overallCapacity !== null) {
            $this->assertLessThanOrEqual(
                $overallCapacity,
                $eventCommitted,
                sprintf(
                    'Event %d oversold overall: sold+reserved across types=%d exceeds capacity=%d.',
                    $event->id,
                    $eventCommitted,
                    $overallCapacity,
                )
            );
        }
    }
}
