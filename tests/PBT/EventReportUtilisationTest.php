<?php

namespace Tests\PBT;

use App\Services\Reporting\EventReport;
use Eris\Generator;

/**
 * Property-based test for capacity utilisation (Requirement 5.4).
 *
 * This is a pure value-object test: {@see EventReport::utilisation()} derives
 * its result solely from the constructed `capacity` and `ticketsSold`, with no
 * database involvement, so no RefreshDatabase trait is used.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy.
 */
class EventReportUtilisationTest extends PbtTestCase
{
    /**
     * Construct an EventReport carrying only the two figures utilisation()
     * depends on; the remaining accounting fields are irrelevant here.
     */
    private function report(?int $capacity, int $ticketsSold): EventReport
    {
        return new EventReport(
            confirmedOrders: 0,
            ticketsSold: $ticketsSold,
            grossRevenueMinor: 0,
            netToCompanyMinor: 0,
            capacity: $capacity,
            perTicketType: [],
            ordersByStatus: [],
            salesByDay: [],
        );
    }

    /**
     * Property 8: Capacity utilisation honours unlimited — over generated
     * `(?int capacity, int ticketsSold)`, `utilisation()` returns the string
     * `'unlimited'` when capacity is null or 0, otherwise the numeric
     * percentage `round(ticketsSold / capacity * 100, 1)`.
     *
     * **Validates: Requirements 5.4**
     */
    // Feature: event-management-and-reporting, Property 8: Capacity utilisation honours unlimited
    public function test_utilisation_honours_unlimited(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Capacity ceiling: sometimes null and sometimes 0 (both meaning
            // unlimited), otherwise a positive integer. Keeping the unlimited
            // sentinels weighted in exercises that branch alongside the maths.
            Generator\oneOf(
                Generator\constant(null),
                Generator\constant(0),
                Generator\choose(1, 1_000_000),
            ),
            // Tickets sold: a non-negative integer whose range overlaps the
            // capacity's so under-, exactly-, and over-capacity all occur.
            Generator\choose(0, 1_000_000),
        )
            ->then(function (?int $capacity, int $ticketsSold): void {
                $result = $this->report($capacity, $ticketsSold)->utilisation();

                if ($capacity === null || $capacity === 0) {
                    $this->assertSame(
                        'unlimited',
                        $result,
                        sprintf(
                            'utilisation() for (capacity=%s, ticketsSold=%d) must be unlimited.',
                            var_export($capacity, true),
                            $ticketsSold
                        )
                    );

                    return;
                }

                $this->assertSame(
                    round($ticketsSold / $capacity * 100, 1),
                    $result,
                    sprintf(
                        'utilisation() for (capacity=%d, ticketsSold=%d)',
                        $capacity,
                        $ticketsSold
                    )
                );
            });

        // Fixed boundary cases guarantee coverage of each branch and the exact
        // edges random generation reaches only rarely.
        $this->assertSame(
            'unlimited',
            $this->report(null, 0)->utilisation(),
            'null capacity must be unlimited.'
        );
        $this->assertSame(
            'unlimited',
            $this->report(null, 500)->utilisation(),
            'null capacity is unlimited regardless of tickets sold.'
        );
        $this->assertSame(
            'unlimited',
            $this->report(0, 0)->utilisation(),
            'zero capacity must be unlimited.'
        );
        $this->assertSame(
            'unlimited',
            $this->report(0, 500)->utilisation(),
            'zero capacity is unlimited regardless of tickets sold.'
        );
        $this->assertSame(
            0.0,
            $this->report(100, 0)->utilisation(),
            'no tickets sold against a fixed capacity is 0.0%.'
        );
        $this->assertSame(
            100.0,
            $this->report(100, 100)->utilisation(),
            'tickets sold equal to capacity is 100.0%.'
        );
        $this->assertSame(
            150.0,
            $this->report(100, 150)->utilisation(),
            'tickets sold above capacity exceeds 100%.'
        );
        $this->assertSame(
            33.3,
            $this->report(3, 1)->utilisation(),
            'utilisation rounds to one decimal place.'
        );
    }
}
