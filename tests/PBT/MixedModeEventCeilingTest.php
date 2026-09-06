<?php

namespace Tests\PBT;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the event-overall ceiling across all capacity modes
 * (design Property 2).
 *
 * Mirrors {@see NoOversellUnderConcurrencyTest}: it uses the Eris library
 * (never hand-rolled generators) with a minimum of 100 iterations and runs
 * against the real MySQL test database so the {@see CapacityReservationService}'s
 * `SELECT ... FOR UPDATE` row locking and count arithmetic behave exactly as in
 * production. Concurrency is modelled without threads as a randomly-generated,
 * interleaved SEQUENCE of reserve/release operations over the event's mix of
 * Ticket_Types; the true serialization guarantee comes from MySQL's FOR UPDATE
 * locks, which this test exercises against the real database.
 *
 * The distinguishing shape here (vs. Property 10): the Event ALWAYS sets a
 * finite overall capacity and always owns a MIX of at least one `capped` type
 * (each with its own per-type capacity) and at least one `shared_pool` type
 * (whose `capacity` is NULL — governed solely by the overall ceiling).
 *
 * The invariant checked after EVERY operation (and at the end):
 *   - Across ALL of the Event's Ticket_Types: `sum(sold_count + reserved_count)`
 *     never exceeds the Event's overall capacity (Requirements 2.4, 2.8, 7.1).
 *   - Each `capped` type: `sold_count + reserved_count <= capacity`
 *     (Requirement 7.2). Shared-pool types have NULL capacity, so NO per-type
 *     ceiling is asserted on them — only the overall sum bounds them.
 *   - Every rejected reserve is atomic: it leaves ALL counts byte-for-byte
 *     unchanged — nothing partially reserved (Requirement 2.8).
 */
class MixedModeEventCeilingTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    /**
     * Property 2: Event-overall ceiling holds across all modes — for an Event
     * with a finite overall capacity and a mix of capped + shared-pool types,
     * across any interleaving of reservations the sum of sold+reserved across
     * ALL types never exceeds the overall capacity, each capped type never
     * exceeds its own capacity, and any reserve that would breach the overall
     * ceiling is rejected in full leaving all counts unchanged.
     *
     * **Validates: Requirements 2.4, 2.8, 7.1, 7.2**
     */
    // Feature: event-experience-polish, Property 2: Event-overall ceiling holds across all modes
    public function test_event_overall_ceiling_holds_across_capped_and_shared_pool_types(): void
    {
        $this->limitTo(self::MIN_ITERATIONS)
            ->forAll(
                // The Event's finite overall capacity (5..30).
                Generator\choose(5, 30),
                // Number of capped types (1–2) and their per-type capacities.
                Generator\choose(1, 2),
                Generator\tuple(
                    Generator\choose(1, 20),
                    Generator\choose(1, 20),
                ),
                // Number of shared-pool types (1–2). Shared-pool types carry
                // NULL capacity, so no capacity pool is needed for them.
                Generator\choose(1, 2),
                // The interleaved operation sequence: each op is [kind,
                // typeIndex, qty] where kind 0 = reserve and 1 = release.
                // typeIndex and qty are normalised against the generated
                // Ticket_Types inside the body.
                Generator\seq(Generator\tuple(
                    Generator\choose(0, 1),
                    Generator\choose(0, 5),
                    Generator\choose(1, 8),
                )),
            )
            ->then(function (
                int $overallCapacity,
                int $cappedCountPick,
                array $cappedCapacityPool,
                int $sharedCountPick,
                array $operations,
            ): void {
                // Each iteration builds its OWN Event + Ticket_Types and only
                // reasons about those rows, so no cleanup of prior iterations is
                // needed. We deliberately avoid table-wide deletes here: a
                // scope-free delete across all events/types would contend with
                // the FOR UPDATE row locks and can deadlock under the tight
                // interleavings this property generates.
                $event = Event::factory()->create(['capacity' => $overallCapacity]);

                $types = [];

                // At least one CAPPED type, each with its own capacity.
                $cappedCapacities = array_slice(array_values($cappedCapacityPool), 0, $cappedCountPick);
                foreach ($cappedCapacities as $capacity) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'capacity' => $capacity,
                        'capacity_mode' => TicketType::MODE_CAPPED,
                        'sold_count' => 0,
                        'reserved_count' => 0,
                    ]);
                }

                // At least one SHARED_POOL type (capacity NULL, mode set by the
                // factory's sharedPool() state).
                for ($i = 0; $i < $sharedCountPick; $i++) {
                    $types[] = TicketType::factory()->forEvent($event)->sharedPool()->create([
                        'sold_count' => 0,
                        'reserved_count' => 0,
                    ]);
                }

                $service = $this->service();
                $typeCount = count($types);

                // Apply the interleaved sequence one op at a time, asserting the
                // ceiling invariant after every step. Releases may exceed a
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
     * Assert the event-ceiling invariant: the Event-wide sum of sold+reserved
     * across ALL its Ticket_Types never exceeds the overall capacity, and each
     * CAPPED type independently never exceeds its own capacity. Shared-pool
     * types have NULL capacity, so no per-type ceiling is asserted on them.
     *
     * @param  list<TicketType>  $types
     */
    private function assertInvariant(Event $event, array $types, int $overallCapacity): void
    {
        $eventCommitted = 0;

        foreach ($types as $type) {
            $fresh = TicketType::withoutGlobalScopes()->findOrFail($type->id);
            $committed = (int) $fresh->sold_count + (int) $fresh->reserved_count;

            // Per-type ceiling only applies to capped types. Shared-pool types
            // carry NULL capacity and are bounded solely by the overall sum.
            if ($fresh->isCapped()) {
                $this->assertLessThanOrEqual(
                    (int) $fresh->capacity,
                    $committed,
                    sprintf(
                        'Capped Ticket_Type %d oversold: sold+reserved=%d exceeds capacity=%d.',
                        $type->id,
                        $committed,
                        $fresh->capacity,
                    )
                );
            }

            // reserved_count must never go negative (release clamps at zero).
            $this->assertGreaterThanOrEqual(
                0,
                (int) $fresh->reserved_count,
                sprintf('Ticket_Type %d reserved_count went negative.', $type->id)
            );

            $eventCommitted += $committed;
        }

        $this->assertLessThanOrEqual(
            $overallCapacity,
            $eventCommitted,
            sprintf(
                'Event %d oversold overall: sold+reserved across all types=%d exceeds capacity=%d.',
                $event->id,
                $eventCommitted,
                $overallCapacity,
            )
        );
    }
}
