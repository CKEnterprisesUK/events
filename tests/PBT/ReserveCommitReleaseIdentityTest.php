<?php

namespace Tests\PBT;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the availability identity under the full lifecycle of
 * reserve / commit / release / releaseSold operations, across BOTH capacity
 * modes (capped + shared-pool) (design Property 7).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the {@see CapacityReservationService}'s
 * `SELECT ... FOR UPDATE` row locking and count arithmetic behave exactly as in
 * production.
 *
 * How the lifecycle is modelled without threads:
 *   The property generates a randomly-interleaved SEQUENCE of the four
 *   count-moving operations the service exposes:
 *     - reserve    (grows reserved_count, gated by capacity)
 *     - commit     (moves reserved_count -> sold_count, clamped at held units)
 *     - release    (returns reserved_count, clamped at zero)
 *     - releaseSold(returns sold_count, clamped at zero)
 *   Each op is applied through the service one at a time. reserve over-requests
 *   raise {@see InsufficientCapacityException} and are treated as rejected;
 *   commit/release/releaseSold clamp so they are always safe in any order.
 *
 * The invariant checked after EVERY operation (and at the end):
 *   - For every Ticket_Type: `sold_count >= 0` and `reserved_count >= 0` —
 *     no bucket is ever driven negative by any of the four operations.
 *   - Capped Ticket_Type: the identity `available = capacity - sold - reserved`
 *     holds with `available >= 0`, i.e. `sold + reserved <= capacity`.
 *   - Shared-pool Ticket_Type: no per-type ceiling — its own counts stay
 *     non-negative but may exceed any nominal per-type figure.
 *   - Across the Event's Ticket_Types: `sum(sold + reserved) <=` the Event's
 *     overall capacity when one is set (NULL = unlimited).
 *
 * The generated data always mixes at least one capped and one shared-pool type
 * so both branches of the identity are exercised on every iteration.
 *
 * **Validates: Requirements 7.1, 7.2**
 */
// Feature: event-experience-polish, Property 7: Release/commit/returns preserve the availability identity under both modes
// **Validates: Requirements 7.1, 7.2**
class ReserveCommitReleaseIdentityTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    public function test_reserve_commit_release_preserve_the_availability_identity_under_both_modes(): void
    {
        $this->forAll(
            // Whether the Event sets an overall capacity (true) or is unlimited
            // (NULL = false). Both gates must hold.
            Generator\bool(),
            // Per-type capacities for the CAPPED types (1-3 of them). The
            // shared-pool type has a null capacity and is added separately, so
            // every iteration mixes at least one capped and one shared-pool type.
            Generator\tuple(
                Generator\choose(1, 10),
                Generator\choose(1, 10),
                Generator\choose(1, 10),
            ),
            Generator\choose(1, 3),
            // The interleaved operation sequence. Each op is [kind, typeIndex,
            // qty] where kind 0 = reserve, 1 = commit, 2 = release, 3 =
            // releaseSold. typeIndex and qty are normalised in the body.
            Generator\seq(Generator\tuple(
                Generator\choose(0, 3),
                Generator\choose(0, 5),
                Generator\choose(1, 6),
            )),
        )
            ->then(function (bool $capped, array $cappedCapacityPool, int $cappedCount, array $operations): void {
                // Each iteration builds its OWN Event + Ticket_Types and only
                // reasons about those rows, so no cleanup of prior iterations is
                // needed (a scope-free table-wide delete would contend with the
                // FOR UPDATE locks and can deadlock under tight interleavings).
                $cappedCapacities = array_slice(array_values($cappedCapacityPool), 0, $cappedCount);

                // When the Event sets an overall capacity, size it generously so
                // both capped and shared-pool holds have room to grow (the
                // per-type identity and the overall ceiling are checked
                // independently). NULL = unlimited.
                $overallCapacity = $capped
                    ? (int) array_sum($cappedCapacities) + 10
                    : null;

                $event = Event::factory()->create(['capacity' => $overallCapacity]);

                /** @var list<TicketType> $types */
                $types = [];

                // At least one CAPPED type.
                foreach ($cappedCapacities as $capacity) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'capacity' => $capacity,
                        'sold_count' => 0,
                        'reserved_count' => 0,
                    ]);
                }

                // At least one SHARED-POOL type (null capacity, no per-type ceiling).
                $types[] = TicketType::factory()->forEvent($event)->sharedPool()->create([
                    'sold_count' => 0,
                    'reserved_count' => 0,
                ]);

                $service = $this->service();
                $typeCount = count($types);

                // Apply the interleaved sequence one op at a time, asserting the
                // identity invariant after every step.
                foreach ($operations as [$kind, $typeIndexRaw, $qty]) {
                    $type = $types[$typeIndexRaw % $typeCount];
                    $quantities = [$type->id => $qty];

                    switch ($kind) {
                        case 0: // reserve — may be rejected when it would oversell.
                            try {
                                $service->reserve($event, $quantities);
                            } catch (InsufficientCapacityException $e) {
                                // Rejected over-request: nothing reserved. The
                                // invariant assertion below still holds.
                            }
                            break;
                        case 1: // commit — moves reserved -> sold, clamped at held units.
                            $service->commit($event, $quantities);
                            break;
                        case 2: // release — returns reserved, clamped at zero.
                            $service->release($event, $quantities);
                            break;
                        case 3: // releaseSold — returns sold, clamped at zero.
                            $service->releaseSold($event, $quantities);
                            break;
                    }

                    $this->assertIdentity($event, $types, $overallCapacity);
                }

                // Identity also holds at the end of the whole sequence.
                $this->assertIdentity($event, $types, $overallCapacity);
            });
    }

    /**
     * Assert the availability identity after an operation:
     *   - every type: sold_count >= 0 and reserved_count >= 0;
     *   - capped type: available = capacity - sold - reserved, available >= 0
     *     (equivalently sold + reserved <= capacity);
     *   - shared-pool type: no per-type ceiling (counts non-negative only);
     *   - Event-wide: sum(sold + reserved) <= overall capacity when set.
     *
     * @param  list<TicketType>  $types
     */
    private function assertIdentity(Event $event, array $types, ?int $overallCapacity): void
    {
        $eventCommitted = 0;

        foreach ($types as $type) {
            $fresh = TicketType::withoutGlobalScopes()->findOrFail($type->id);
            $sold = (int) $fresh->sold_count;
            $reserved = (int) $fresh->reserved_count;

            // No bucket is ever driven negative by any operation. (Req 7.1)
            $this->assertGreaterThanOrEqual(
                0,
                $sold,
                sprintf('Ticket_Type %d sold_count went negative (%d).', $type->id, $sold)
            );
            $this->assertGreaterThanOrEqual(
                0,
                $reserved,
                sprintf('Ticket_Type %d reserved_count went negative (%d).', $type->id, $reserved)
            );

            if ($fresh->isCapped()) {
                // Capped identity: available = capacity - sold - reserved, and
                // available is never negative. (Req 7.2)
                $available = (int) $fresh->capacity - $sold - $reserved;

                $this->assertGreaterThanOrEqual(
                    0,
                    $available,
                    sprintf(
                        'Capped Ticket_Type %d violated the identity: capacity=%d sold=%d reserved=%d available=%d.',
                        $type->id,
                        $fresh->capacity,
                        $sold,
                        $reserved,
                        $available,
                    )
                );
            }
            // Shared-pool types have no per-type ceiling; only the non-negative
            // checks above apply to them. (Req 7.2)

            $eventCommitted += $sold + $reserved;
        }

        if ($overallCapacity !== null) {
            $this->assertLessThanOrEqual(
                $overallCapacity,
                $eventCommitted,
                sprintf(
                    'Event %d exceeded overall capacity: sold+reserved across types=%d exceeds capacity=%d.',
                    $event->id,
                    $eventCommitted,
                    $overallCapacity,
                )
            );
        }
    }
}
