<?php

namespace Tests\PBT;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\CompTicketService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

/**
 * Property-based test for the capacity binding of complimentary ("comp")
 * issuance (design Property 8).
 *
 * Mirrors {@see MixedModeEventCeilingTest}: it uses the Eris library (never
 * hand-rolled generators) with a minimum of 100 iterations and runs against the
 * real MySQL test database so the {@see \App\Services\CapacityReservationService}'s
 * `SELECT ... FOR UPDATE` row locking and count arithmetic behave exactly as in
 * production.
 *
 * The property under test: comp issuance consumes capacity through the SAME
 * reservation path as a paid sale ({@see CompTicketService} → the reservation
 * service), so a comp for `{type => q}` is admitted if and only if the SAME
 * quantity would be admitted as a paid reservation under that type's capacity
 * mode:
 *   - a `capped` type is bound by BOTH its own per-type remaining
 *     (`capacity - sold - reserved`) AND the Event's overall remaining;
 *   - a `shared_pool` type is bound by the Event's overall remaining ONLY.
 *
 * When the request fits, {@see CompTicketService::issue()} creates a single
 * `free_confirmed` Order and moves `q` units into `sold_count` for the target
 * type; when it does not, it throws {@see InsufficientCapacityException} and
 * NOTHING is issued — no new Order row, and every count is left byte-for-byte
 * unchanged. (Requirements 6.4, 6.5)
 *
 * The comp is driven directly through {@see CompTicketService::issue()} rather
 * than the HTTP route so the capacity-binding property is isolated from the
 * gate/tenant HTTP concerns exercised by
 * {@see \Tests\Feature\ComplimentaryTicketIssuanceTest}. The ticket-email queue
 * is faked so fulfilment enqueues nothing real.
 */
class CompCapacityModeTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CompTicketService
    {
        return app(CompTicketService::class);
    }

    /**
     * Property 8: comp issuance is bound by capacity in the same way as a paid
     * reservation. For an Event with a finite overall capacity and a mix of one
     * capped type (its own per-type capacity) and one shared-pool type
     * (governed by the overall ceiling only), issuing a comp for `q` of a chosen
     * type succeeds IFF the same `q` would be admitted as a paid reservation:
     * capped ⇒ `q <= min(perTypeRemaining, overallRemaining)`; shared-pool ⇒
     * `q <= overallRemaining`. On success a `free_confirmed` Order is created and
     * `q` units become sold; on rejection the reservation throws and nothing is
     * issued (no Order, counts unchanged).
     *
     * **Validates: Requirements 6.4, 6.5**
     */
    // Feature: event-experience-polish, Property 8: Comp issuance is bound by capacity in the same way as a paid reservation
    public function test_comp_issuance_is_bound_like_a_paid_reservation(): void
    {
        Queue::fake();

        $this->limitTo(self::MIN_ITERATIONS)
            ->forAll(
                // The Event's finite overall capacity (2..12).
                Generator\choose(2, 12),
                // The capped type's own per-type capacity (1..12).
                Generator\choose(1, 12),
                // Pre-seed sold/reserved on the capped type to vary remaining.
                Generator\choose(0, 6),
                Generator\choose(0, 6),
                // Pre-seed sold/reserved on the shared-pool type.
                Generator\choose(0, 6),
                Generator\choose(0, 6),
                // Which type to target the comp at: 0 = capped, 1 = shared_pool.
                Generator\choose(0, 1),
                // The requested comp quantity (1..14 — spans both fit and
                // over-request outcomes across iterations).
                Generator\choose(1, 14),
            )
            ->then(function (
                int $overallCapacity,
                int $cappedCapacity,
                int $cappedSold,
                int $cappedReserved,
                int $sharedSold,
                int $sharedReserved,
                int $targetPick,
                int $requestQty,
            ): void {
                // Clamp the capped pre-seed so the capped type is never itself
                // seeded over its own capacity (that would be an invalid fixture,
                // not a property under test). Then clamp the whole event's
                // pre-seed so the overall committed total never exceeds the
                // overall capacity either — the fixture must start in a legal
                // state so we only test the reserve decision, not a broken seed.
                $cappedSold = min($cappedSold, $cappedCapacity);
                $cappedReserved = min($cappedReserved, max(0, $cappedCapacity - $cappedSold));

                $cappedCommitted = $cappedSold + $cappedReserved;
                $sharedBudget = max(0, $overallCapacity - $cappedCommitted);

                $sharedSold = min($sharedSold, $sharedBudget);
                $sharedReserved = min($sharedReserved, max(0, $sharedBudget - $sharedSold));

                // Each iteration builds its OWN Company + Event + Ticket_Types
                // and only reasons about those rows, so no cleanup of prior
                // iterations is needed (a scope-free table-wide delete would
                // contend with FOR UPDATE locks). Mirrors MixedModeEventCeiling.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->create(['capacity' => $overallCapacity]);

                // Comp issuance runs the tenant-scoped queries the controller
                // would run under a resolved tenant (belongs-to-event checks,
                // Order creation/lookup). Resolve the tenant to this iteration's
                // Company so those queries see this Event's own rows — the same
                // context the `dashboard.tenant` group establishes in prod.
                app(TenantContext::class)->setCompany($company);

                $cappedType = TicketType::factory()->forEvent($event)->create([
                    'capacity' => $cappedCapacity,
                    'capacity_mode' => TicketType::MODE_CAPPED,
                    'sold_count' => $cappedSold,
                    'reserved_count' => $cappedReserved,
                ]);

                $sharedType = TicketType::factory()->forEvent($event)->sharedPool()->create([
                    'sold_count' => $sharedSold,
                    'reserved_count' => $sharedReserved,
                ]);

                $targetType = $targetPick === 0 ? $cappedType : $sharedType;

                // Compute the paid-reservation predicate from the current
                // committed state: this is the SAME rule the reservation service
                // applies. Overall remaining spans BOTH types (sum of
                // sold+reserved across the event).
                $overallCommitted = $cappedCommitted + $sharedSold + $sharedReserved;
                $overallRemaining = $overallCapacity - $overallCommitted;

                if ($targetType->getKey() === $cappedType->getKey()) {
                    // Capped: bound by BOTH the per-type remaining AND overall.
                    $perTypeRemaining = $cappedCapacity - $cappedSold - $cappedReserved;
                    $wouldAdmit = $requestQty <= min($perTypeRemaining, $overallRemaining);
                } else {
                    // Shared-pool: bound by the overall remaining ONLY.
                    $wouldAdmit = $requestQty <= $overallRemaining;
                }

                $quantities = [$targetType->id => $requestQty];

                // Snapshot the committed state so a rejected issuance can be
                // proven to have changed nothing.
                $before = $this->countsSnapshot([$cappedType, $sharedType]);
                $ordersBefore = Order::withoutGlobalScopes()->where('event_id', $event->id)->count();

                $issued = null;
                $threw = false;

                try {
                    $issued = $this->service()->issue(
                        $company,
                        $event,
                        $quantities,
                        'Comp Recipient',
                        'comp@example.com',
                    );
                } catch (InsufficientCapacityException $e) {
                    $threw = true;
                }

                if ($wouldAdmit) {
                    // A comp that a paid reserve would admit must succeed and
                    // move exactly q units into the target type's sold bucket.
                    $this->assertFalse(
                        $threw,
                        sprintf(
                            'Comp for %d of type %d should have been admitted (perTypeRemaining/overall permit it) but was rejected.',
                            $requestQty,
                            $targetType->id,
                        )
                    );

                    $this->assertInstanceOf(Order::class, $issued);
                    $this->assertSame(Order::STATUS_FREE_CONFIRMED, $issued->status);

                    // Exactly one new Order was created for this event, with q
                    // tickets on it.
                    $this->assertSame(
                        $ordersBefore + 1,
                        Order::withoutGlobalScopes()->where('event_id', $event->id)->count(),
                        'A successful comp must create exactly one new Order.'
                    );
                    $this->assertSame(
                        $requestQty,
                        Ticket::withoutGlobalScopes()->where('order_id', $issued->id)->count(),
                        'A successful comp must create one Ticket per comp ticket.'
                    );

                    // The held units are committed reserved → sold: the target
                    // type's sold_count rose by exactly q; the other type is
                    // untouched.
                    $after = $this->countsSnapshot([$cappedType, $sharedType]);

                    $this->assertSame(
                        $before[$targetType->id]['sold'] + $requestQty,
                        $after[$targetType->id]['sold'],
                        'A successful comp must increment the target type sold_count by q.'
                    );
                    $this->assertSame(
                        $before[$targetType->id]['reserved'],
                        $after[$targetType->id]['reserved'],
                        'A comp fulfils immediately, so reserved_count returns to its prior value.'
                    );

                    $otherId = $targetType->getKey() === $cappedType->getKey()
                        ? $sharedType->id
                        : $cappedType->id;

                    $this->assertSame(
                        $before[$otherId],
                        $after[$otherId],
                        'Issuing against one type must leave the other type unchanged.'
                    );
                } else {
                    // A comp that a paid reserve would REJECT must throw and
                    // issue nothing: no new Order, counts byte-for-byte unchanged.
                    $this->assertTrue(
                        $threw,
                        sprintf(
                            'Comp for %d of type %d should have been rejected (exceeds availability) but succeeded.',
                            $requestQty,
                            $targetType->id,
                        )
                    );

                    $this->assertSame(
                        $ordersBefore,
                        Order::withoutGlobalScopes()->where('event_id', $event->id)->count(),
                        'A rejected comp must create no Order.'
                    );

                    $this->assertSame(
                        $before,
                        $this->countsSnapshot([$cappedType, $sharedType]),
                        'A rejected comp must leave every count unchanged (atomic).'
                    );
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
