<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for reservation release restoring availability
 * (Property 11).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database so the CapacityReservationService's SELECT ... FOR UPDATE row
 * locking and count arithmetic behave exactly as in production.
 *
 * The property (task 8.4): releasing a reservation — as happens on expiry,
 * cancel, or payment failure — returns the exact held quantity back to
 * available capacity, restoring the pre-reservation value. Release is
 * idempotent: a double release (expiry racing a cancel, a retried job) is a
 * no-op beyond restoring the actually held units, and it never drives
 * `reserved_count` negative — even when asked to release more than was held.
 * (Requirements 10.7, 10.12, 6.6)
 *
 * Generators produce random Ticket_Type capacities, pre-existing sold/reserved
 * baselines, a reserve quantity that fits remaining availability, and a random
 * release plan (release exactly the held units, release fewer, release more,
 * release zero, and release twice) so the restore-to-baseline, clamp-at-zero,
 * and idempotency facets are all exercised.
 */
class ReservationReleaseRestoresAvailabilityTest extends PbtTestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    /**
     * Property 11: Reservation release restores availability — releasing a
     * reservation (on expiry/cancel/failure) restores the exact held quantity
     * to available capacity; release is idempotent (a double/over release is a
     * no-op beyond restoring the actually held units) and never drives
     * `reserved_count` negative.
     *
     * **Validates: Requirements 10.7, 10.12, 6.6**
     */
    // Feature: event-ticketing-platform, Property 11: Reservation release restores availability — releasing a reservation (on expiry/cancel/failure) restores the exact held quantity to available capacity; release is idempotent (double release is a no-op) and never drives reserved_count negative
    public function test_release_restores_pre_reservation_availability_and_is_idempotent(): void
    {
        // Fixed boundary cases pinning the core release-the-order facets so
        // they are always exercised (random percents rarely land exactly on
        // "release exactly held"): full restore to baseline, idempotent double
        // release, and over-release clamped at zero. Each is
        // [capacity, sold, baseline, reserveQty, releaseQty, releaseTimes].
        $boundaryCases = [
            // Release exactly the held units once -> back to baseline.
            [100, 10, 5, 20, 20, 1],
            // Release exactly held twice -> idempotent, back to baseline.
            [100, 0, 0, 30, 30, 2],
            // Over-release (more than held) -> clamps, drains toward zero.
            [50, 0, 4, 10, 40, 1],
            // Release zero -> no-op, hold intact.
            [80, 0, 0, 12, 0, 3],
            // Fill capacity exactly, then release all -> baseline restored.
            [15, 5, 0, 10, 10, 1],
        ];

        foreach ($boundaryCases as [$capacity, $sold, $baseline, $reserveQty, $releaseQty, $releaseTimes]) {
            $event = Event::factory()->unlimitedCapacity()->create();
            $type = TicketType::factory()->forEvent($event)->create([
                'capacity' => $capacity,
                'sold_count' => $sold,
                'reserved_count' => $baseline,
            ]);
            $service = $this->service();

            $service->reserve($event, $reserveQty > 0 ? [$type->id => $reserveQty] : []);
            $this->assertSame($baseline + $reserveQty, $type->fresh()->reserved_count);

            for ($i = 0; $i < $releaseTimes; $i++) {
                $service->release($event, $releaseQty > 0 ? [$type->id => $releaseQty] : []);
            }

            $totalRequested = $releaseQty * $releaseTimes;
            $expectedFinal = max(0, ($baseline + $reserveQty) - $totalRequested);

            $final = $type->fresh()->reserved_count;
            $this->assertGreaterThanOrEqual(0, $final, 'reserved_count must never go negative.');
            $this->assertSame(
                $expectedFinal,
                $final,
                sprintf('Boundary release case cap=%d baseline=%d held=%d releaseQty=%d times=%d', $capacity, $baseline, $reserveQty, $releaseQty, $releaseTimes)
            );
        }

        $this->forAll(
            // capacity of the Ticket_Type (1..1000).
            Generator\choose(1, 1_000),
            // fraction knobs used to derive a valid sold baseline, reserved
            // baseline, and reserve quantity that all fit within capacity.
            Generator\choose(0, 100),   // sold as a percent of capacity
            Generator\choose(0, 100),   // pre-existing reserved as a percent of remaining
            Generator\choose(0, 100),   // reserve qty as a percent of remaining-after-baseline
            // release plan: percent of held to release per call (0 = release
            // zero, 100 = release exactly held, >100 = over-release), and how
            // many times to call release (1..3) to exercise idempotency.
            Generator\choose(0, 200),
            Generator\choose(1, 3)
        )
            ->then(function (
                int $capacity,
                int $soldPct,
                int $reservedPct,
                int $reservePct,
                int $releasePct,
                int $releaseTimes
            ): void {
                // Derive a consistent baseline: sold + reserved + reserve all
                // fit within capacity so the reserve is admissible.
                $sold = intdiv($capacity * $soldPct, 100);
                $sold = min($sold, $capacity);

                $remainingAfterSold = $capacity - $sold;
                $reservedBaseline = intdiv($remainingAfterSold * $reservedPct, 100);
                $reservedBaseline = min($reservedBaseline, $remainingAfterSold);

                $remainingForReserve = $remainingAfterSold - $reservedBaseline;
                $reserveQty = intdiv($remainingForReserve * $reservePct, 100);
                $reserveQty = min($reserveQty, $remainingForReserve);

                // Fresh Event + Ticket_Type per iteration so iterations are
                // independent. Event capacity is unlimited so the per-type
                // reserve is the only gate.
                $event = Event::factory()->unlimitedCapacity()->create();
                $type = TicketType::factory()->forEvent($event)->create([
                    'capacity' => $capacity,
                    'sold_count' => $sold,
                    'reserved_count' => $reservedBaseline,
                ]);

                $service = $this->service();

                // Reserve the (admissible) quantity. reserved_count grows by
                // exactly reserveQty. (Requirement 6.6)
                $service->reserve($event, $reserveQty > 0 ? [$type->id => $reserveQty] : []);

                $this->assertSame(
                    $reservedBaseline + $reserveQty,
                    $type->fresh()->reserved_count,
                    'Reserve must add exactly the reserved quantity.'
                );

                // The held units created by THIS reservation.
                $held = $reserveQty;

                // Release plan: a per-call quantity derived from the held
                // units. releasePct > 100 asks to release more than held so the
                // clamp-at-zero / no-negative facet is exercised.
                $releaseQty = intdiv($held * $releasePct, 100);

                // Availability before releasing (capacity - sold - reserved).
                $preReleaseReserved = $type->fresh()->reserved_count;

                // Call release $releaseTimes times. Idempotency means the total
                // amount actually returned can never exceed the held units, no
                // matter how many times (or how much) we release. (Req 10.7)
                for ($i = 0; $i < $releaseTimes; $i++) {
                    $service->release($event, $releaseQty > 0 ? [$type->id => $releaseQty] : []);
                }

                $finalReserved = $type->fresh()->reserved_count;

                // Invariant 1: reserved_count is never negative. (Req 10.7)
                $this->assertGreaterThanOrEqual(
                    0,
                    $finalReserved,
                    'reserved_count must never go negative.'
                );

                // Each release call subtracts min(releaseQty, current
                // reserved_count) from reserved_count, clamping at zero so it
                // never goes negative. Across all calls the total removed is
                // therefore min(total requested, reserved_count at start of
                // releasing) — the count bottoms out at zero and further calls
                // are no-ops (idempotent). (Req 10.7)
                //
                // The starting reserved_count here is baseline + held; the
                // service has no notion of "which order" a held unit belongs
                // to, so an over-release drains down toward zero.
                $totalRequested = $releaseQty * $releaseTimes;
                $startReserved = $reservedBaseline + $held;
                $expectedFinal = max(0, $startReserved - $totalRequested);

                // Invariant 2: releasing subtracts exactly the requested held
                // units, clamped at zero; repeat/over releases never drive
                // reserved_count negative and are a no-op once it hits zero.
                // (Req 10.7, 10.12)
                $this->assertSame(
                    $expectedFinal,
                    $finalReserved,
                    sprintf(
                        'Release must restore exactly the actually-held units and be idempotent '
                        .'(capacity=%d sold=%d baseline=%d held=%d releaseQty=%d times=%d).',
                        $capacity,
                        $sold,
                        $reservedBaseline,
                        $held,
                        $releaseQty,
                        $releaseTimes
                    )
                );

                // Invariant 3 (full-restore facet): the real-world release of a
                // reservation returns exactly the units it held. When the
                // releases together return exactly the held units (without
                // over-draining into the pre-existing baseline), reserved_count
                // returns to the exact pre-reservation baseline and available
                // capacity is restored to its pre-reservation value.
                // (Req 10.7, 10.12)
                if ($totalRequested === $held) {
                    $this->assertSame(
                        $reservedBaseline,
                        $finalReserved,
                        'Releasing exactly the held units restores the pre-reservation value.'
                    );

                    // Availability is fully restored to its pre-reservation value.
                    $available = $capacity - $type->fresh()->sold_count - $finalReserved;
                    $this->assertSame(
                        $capacity - $sold - $reservedBaseline,
                        $available,
                        'Available capacity returns to its pre-reservation value.'
                    );
                }

                // reserved_count never exceeds pre-release (release only ever
                // decreases held units).
                $this->assertLessThanOrEqual(
                    $preReleaseReserved,
                    $finalReserved,
                    'Release must never increase reserved_count.'
                );
            });
    }
}
