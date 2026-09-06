<?php

namespace Tests\PBT;

use App\Models\TicketType;
use Eris\Generator;

/**
 * Property-based test for mode-aware availability computation (Requirements
 * 2.5, 2.6, 7.2).
 *
 * This is a pure value test: {@see TicketType::availabilityFor()} reads only
 * `capacity_mode`, `capacity`, `sold_count`, and `reserved_count` from the
 * model plus the passed-in event overall remaining. No persisted state or
 * relations are consulted, so the model is built with `forceFill()` and never
 * saved — no database is touched and no RefreshDatabase trait is needed.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy.
 */
class AvailabilityForModeTest extends PbtTestCase
{
    /**
     * Property 4: Availability computation matches the capacity mode — over a
     * generated ticket type and event overall remaining `r` (null =
     * unlimited):
     *   - capped:      availabilityFor(r) == min(capacity - sold - reserved, r)
     *                  when r !== null, else (capacity - sold - reserved).
     *   - shared_pool: availabilityFor(r) === r (null stays null).
     *
     * **Validates: Requirements 2.5, 2.6, 7.2**
     */
    // Feature: event-experience-polish, Property 4: Availability computation matches the capacity mode
    public function test_availability_for_matches_the_capacity_mode(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Capacity mode: capped keeps a per-type ceiling; shared_pool has none.
            Generator\elements(TicketType::MODE_CAPPED, TicketType::MODE_SHARED_POOL),
            // Per-type capacity, sold, and reserved counts (used only when capped).
            Generator\choose(0, 1_000),
            Generator\choose(0, 1_000),
            Generator\choose(0, 1_000),
            // Event overall remaining: sometimes null (unlimited), else non-negative.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(0, 1_000),
            ),
        )
            ->then(function (
                string $mode,
                int $capacity,
                int $sold,
                int $reserved,
                ?int $eventRemaining,
            ): void {
                // shared_pool ignores capacity; model it as null to match production shape.
                $isShared = $mode === TicketType::MODE_SHARED_POOL;

                $type = (new TicketType)->forceFill([
                    'capacity_mode' => $mode,
                    'capacity' => $isShared ? null : $capacity,
                    'sold_count' => $sold,
                    'reserved_count' => $reserved,
                ]);

                // Independently computed oracle (does not reuse production logic).
                if ($isShared) {
                    $expected = $eventRemaining; // null stays null
                } else {
                    $perType = $capacity - $sold - $reserved;
                    $expected = $eventRemaining === null
                        ? $perType
                        : min($perType, $eventRemaining);
                }

                $this->assertSame(
                    $expected,
                    $type->availabilityFor($eventRemaining),
                    sprintf(
                        'availabilityFor for (mode=%s, capacity=%d, sold=%d, reserved=%d, eventRemaining=%s)',
                        $mode,
                        $capacity,
                        $sold,
                        $reserved,
                        var_export($eventRemaining, true),
                    )
                );
            });

        // Fixed cases guarantee coverage of each branch and boundary that
        // random generation reaches only rarely.
        $capped = fn (int $cap, int $sold, int $reserved) => (new TicketType)->forceFill([
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => $cap,
            'sold_count' => $sold,
            'reserved_count' => $reserved,
        ]);
        $shared = (new TicketType)->forceFill([
            'capacity_mode' => TicketType::MODE_SHARED_POOL,
            'capacity' => null,
            'sold_count' => 3,
            'reserved_count' => 2,
        ]);

        // Capped, unlimited event → per-type remaining.
        $this->assertSame(
            15,
            $capped(20, 3, 2)->availabilityFor(null),
            'capped with null event remaining returns the per-type remaining.'
        );
        // Capped, event remaining binds (smaller than per-type).
        $this->assertSame(
            5,
            $capped(20, 3, 2)->availabilityFor(5),
            'capped clamps to the event remaining when it is smaller.'
        );
        // Capped, per-type binds (smaller than event remaining).
        $this->assertSame(
            15,
            $capped(20, 3, 2)->availabilityFor(100),
            'capped clamps to the per-type remaining when it is smaller.'
        );
        // Shared pool follows the event remaining verbatim.
        $this->assertSame(
            7,
            $shared->availabilityFor(7),
            'shared_pool returns the event remaining unchanged.'
        );
        // Shared pool with unlimited event stays null.
        $this->assertNull(
            $shared->availabilityFor(null),
            'shared_pool with null event remaining stays null (unlimited).'
        );
    }
}
