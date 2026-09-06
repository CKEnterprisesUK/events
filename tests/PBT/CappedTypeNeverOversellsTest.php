<?php

namespace Tests\PBT;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the capped-specific slice of no-oversell
 * (Property 1 of the event-experience-polish design).
 *
 * This is deliberately the capped-only projection of the broader no-oversell
 * property ({@see NoOversellUnderConcurrencyTest}): every Ticket_Type here is
 * built explicitly with `capacity_mode = capped` so the property locks in that
 * the reservation engine's new mode-aware per-type branch still enforces the
 * capped per-type ceiling exactly as before. The Event carries NO overall
 * capacity (`capacity = null`), so the ONLY thing that can reject an
 * over-request is a capped type's own per-type ceiling — isolating the capped
 * behaviour under test.
 *
 * Like the sibling suite it uses the Eris library (never hand-rolled
 * generators), runs a minimum of 100 iterations, and executes against the real
 * MySQL test database so the {@see CapacityReservationService}'s
 * `SELECT ... FOR UPDATE` row locking and count arithmetic behave exactly as
 * in production.
 *
 * How concurrency is modelled without threads:
 *   The serialization guarantee comes from MySQL's FOR UPDATE locks, exercised
 *   against the real database. Concurrency is represented as a randomly
 *   generated, interleaved SEQUENCE of reserve/release operations over a mix of
 *   capped Ticket_Types and quantities. Each operation is applied through the
 *   service; over-requests raise {@see InsufficientCapacityException} and are
 *   treated as rejected. Every ordering reachable in production is a
 *   serialization of concurrent requests, which is exactly what an interleaved
 *   sequence enumerates.
 *
 * The invariant checked after EVERY operation (and at the end):
 *   - For every capped Ticket_Type: `sold_count + reserved_count <= capacity`
 *     (Requirements 2.5, 2.7, 7.2).
 *   - `reserved_count` never goes negative (release clamps at zero).
 *   - Every rejected reserve is atomic: it leaves ALL counts byte-for-byte
 *     unchanged — nothing partially reserved (Requirements 2.7).
 */
class CappedTypeNeverOversellsTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    /**
     * Property 1: A capped Ticket_Type never oversells its own capacity —
     * across any interleaving of reserve/release operations, every capped
     * type's sold+reserved stays within its capacity, and any reserve that
     * would exceed the per-type ceiling is rejected in full, leaving all
     * counts unchanged.
     *
     * **Validates: Requirements 2.5, 2.7, 7.2**
     */
    // Feature: event-experience-polish, Property 1: Capped type never oversells its own capacity
    public function test_capped_type_never_oversells_its_own_capacity(): void
    {
        $this->limitTo(self::MIN_ITERATIONS)
            ->forAll(
                // The number of capped Ticket_Types (1–3) and a pool of small
                // candidate capacities; the body slices the pool to the chosen
                // count so each type gets a capacity the sequence can bump
                // against.
                Generator\choose(1, 3),
                Generator\tuple(
                    Generator\choose(1, 12),
                    Generator\choose(1, 12),
                    Generator\choose(1, 12),
                ),
                // The interleaved operation sequence: each op is [kind,
                // typeIndex, qty] where kind 0 = reserve and 1 = release.
                // typeIndex and qty are normalised against the generated types
                // inside the body.
                Generator\seq(Generator\tuple(
                    Generator\choose(0, 1),
                    Generator\choose(0, 5),
                    Generator\choose(1, 8),
                )),
            )
            ->then(function (int $typeCountPick, array $capacityPool, array $operations): void {
                // Each iteration builds its OWN Event + capped Ticket_Types and
                // only reasons about those rows, so no cleanup of prior
                // iterations is needed (a scope-free table-wide delete would
                // contend with the FOR UPDATE row locks and can deadlock).
                //
                // The Event has NO overall capacity so the per-type capped
                // ceiling is the sole governor — this isolates capped
                // behaviour, which is the point of Property 1.
                $ticketTypeCapacities = array_slice(array_values($capacityPool), 0, $typeCountPick);

                $event = Event::factory()->create(['capacity' => null]);

                $types = [];
                foreach ($ticketTypeCapacities as $capacity) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'capacity' => $capacity,
                        'capacity_mode' => TicketType::MODE_CAPPED,
                        'sold_count' => 0,
                        'reserved_count' => 0,
                    ]);
                }

                $service = $this->service();
                $typeCount = count($types);

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
                            // Rejected over-request: must be fully atomic — no
                            // count anywhere may have moved.
                            $this->assertSame(
                                $before,
                                $this->countsSnapshot($types),
                                'A rejected reserve must leave every capped count unchanged (atomic).'
                            );
                        }
                    } else {
                        $service->release($event, $quantities);
                    }

                    // Invariant holds after EVERY operation, accepted or not.
                    $this->assertInvariant($types);
                }

                // Invariant also holds at the end of the whole sequence.
                $this->assertInvariant($types);
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
     * Assert the capped no-oversell invariant: for every capped Ticket_Type,
     * sold+reserved never exceeds its own capacity, and reserved_count never
     * goes negative.
     *
     * @param  list<TicketType>  $types
     */
    private function assertInvariant(array $types): void
    {
        foreach ($types as $type) {
            $fresh = TicketType::withoutGlobalScopes()->findOrFail($type->id);
            $committed = (int) $fresh->sold_count + (int) $fresh->reserved_count;

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

            $this->assertGreaterThanOrEqual(
                0,
                (int) $fresh->reserved_count,
                sprintf('Capped Ticket_Type %d reserved_count went negative.', $type->id)
            );
        }
    }
}
