<?php

namespace Tests\PBT;

use App\Services\Events\CapacityComparison;
use Eris\Generator;

/**
 * Property-based test for the capacity-ceiling classification (Requirements
 * 3.2, 3.3).
 *
 * This is a pure value-object test: {@see CapacityComparison} derives its
 * state from two integers (an optional overall ceiling and the ticket-type
 * sum) with no database involvement, so no RefreshDatabase trait is used.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy.
 */
class CapacityComparisonTest extends PbtTestCase
{
    /**
     * The independently-computed oracle for which ceiling binds. Mirrors the
     * null/`<`/`>`/`=` classification without reusing production code.
     */
    private function expectedState(?int $capacity, int $typesSum): string
    {
        if ($capacity === null) {
            return CapacityComparison::UNLIMITED;
        }

        if ($capacity < $typesSum) {
            return CapacityComparison::EVENT_BINDS;
        }

        if ($capacity > $typesSum) {
            return CapacityComparison::TYPES_BIND;
        }

        return CapacityComparison::BALANCED;
    }

    /**
     * Property 5: Capacity comparison classifies the ceiling correctly — over
     * generated `(?int capacity, int typesSum)`, `state()` returns UNLIMITED
     * when capacity is null, EVENT_BINDS when capacity < typesSum, TYPES_BIND
     * when capacity > typesSum, and BALANCED when the two are equal.
     *
     * **Validates: Requirements 3.2, 3.3**
     */
    // Feature: event-management-and-reporting, Property 5: Capacity comparison classifies the ceiling correctly
    public function test_state_classifies_the_binding_ceiling(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Optional overall ceiling: sometimes null (unlimited), otherwise a
            // non-negative integer. Weighting null in keeps the unlimited case
            // exercised alongside the numeric comparisons.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(0, 1_000_000),
            ),
            // Ticket-type capacity sum: a non-negative integer. Its range
            // overlaps the ceiling's so <, >, and = all occur.
            Generator\choose(0, 1_000_000),
        )
            ->then(function (?int $capacity, int $typesSum): void {
                $comparison = new CapacityComparison($capacity, $typesSum);

                $this->assertSame(
                    $this->expectedState($capacity, $typesSum),
                    $comparison->state(),
                    sprintf(
                        'state() for (capacity=%s, typesSum=%d)',
                        var_export($capacity, true),
                        $typesSum
                    )
                );
            });

        // Fixed cases guarantee coverage of each branch and the exact boundary
        // that random generation reaches only rarely.
        $this->assertSame(
            CapacityComparison::UNLIMITED,
            (new CapacityComparison(null, 0))->state(),
            'null capacity must classify as UNLIMITED.'
        );
        $this->assertSame(
            CapacityComparison::UNLIMITED,
            (new CapacityComparison(null, 500))->state(),
            'null capacity is UNLIMITED regardless of the ticket-type sum.'
        );
        $this->assertSame(
            CapacityComparison::EVENT_BINDS,
            (new CapacityComparison(10, 25))->state(),
            'capacity below the ticket-type sum must classify as EVENT_BINDS.'
        );
        $this->assertSame(
            CapacityComparison::TYPES_BIND,
            (new CapacityComparison(40, 25))->state(),
            'capacity above the ticket-type sum must classify as TYPES_BIND.'
        );
        $this->assertSame(
            CapacityComparison::BALANCED,
            (new CapacityComparison(25, 25))->state(),
            'capacity equal to the ticket-type sum must classify as BALANCED.'
        );
    }
}
