<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\EventReportService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Property-based test for the sales-over-time trend computed by
 * {@see EventReportService::salesByDay()} (feature Property 11).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, running against the real MySQL
 * test database.
 *
 * Over generated populations of an Event's CONFIRMED orders (status paid or
 * free_confirmed) — each assigned to one of a few distinct days — the property
 * asserts that `EventReportService::for($event)->salesByDay` reports, for every
 * day, `tickets` equal to the sum of `valid` tickets on that day's confirmed
 * orders and `revenue_minor` equal to the sum of `order_total_minor` over those
 * same orders. The service groups by the `fulfilled_at` day (Y-m-d), falling
 * back to `created_at` when `fulfilled_at` is null; the test exercises that
 * fallback by leaving some orders' `fulfilled_at` null with a known
 * `created_at`. The returned rows must be in ascending chronological order by
 * `day`. The expected per-day figures are computed independently in the test
 * from the generated population, then compared against the service.
 *
 * The EventReportService queries Orders/Tickets, which are tenant-scoped, so
 * the Event's Company is established as the active tenant for each iteration
 * (mirroring EventReportAccountingTest) and cleared in tearDown.
 */
class EventReportSalesByDayTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The two confirmed statuses whose money and valid tickets appear in the
     * sales-over-time trend.
     *
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    /**
     * The distinct days (offsets in days back from "today") an order may be
     * placed on. A handful of separate days is enough to exercise grouping and
     * chronological ordering while keeping data small.
     *
     * @var list<int>
     */
    private const DAY_OFFSETS = [0, 1, 2];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 11: Sales-over-time matches grouped sums — for any generated
     * population of an Event's confirmed orders spread across a few days, every
     * day reported by EventReportService::salesByDay carries tickets and
     * revenue_minor equal to the independently-computed sums over the confirmed
     * orders fulfilled on that day (using created_at when fulfilled_at is null),
     * and the rows are in ascending chronological order by day.
     *
     * **Validates: Requirements 6.5**
     */
    // Feature: event-management-and-reporting, Property 11: Sales-over-time matches grouped sums
    public function test_sales_by_day_matches_grouped_sums(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // A small sequence of confirmed-order seeds — one per Order.
            // Keeping the population tiny (0–6 orders) keeps each of the 100
            // iterations fast while still exercising multi-day grouping.
            Generator\seq(Generator\tuple(
                // 0 => paid, 1 => free_confirmed.
                Generator\choose(0, count(self::CONFIRMED_STATUSES) - 1),
                // Index into DAY_OFFSETS: which day this order lands on.
                Generator\choose(0, count(self::DAY_OFFSETS) - 1),
                // Whether fulfilled_at is null (1) — exercising the created_at
                // fallback — or set (0). Either way the day is the same offset.
                Generator\choose(0, 1),
                // Number of VALID tickets on this order (0–4).
                Generator\choose(0, 4),
                // Number of VOIDED tickets on this order (0–3) — never counted.
                Generator\choose(0, 3),
                // The order total in minor units.
                Generator\choose(0, 100_000),
            )),
        )
            ->then(function (array $orderSeeds): void {
                // Cap the population at 6 orders to keep iterations fast.
                $orderSeeds = array_slice(array_values($orderSeeds), 0, 6);

                // Build a single Company/Event and establish the Company as the
                // active tenant so the tenant-scoped Order/Ticket queries inside
                // EventReportService resolve against this Company's rows.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->unlimitedCapacity()->create();

                app(TenantContext::class)->setCompany($company);

                // One Ticket_Type for the Event; every Ticket hangs off it.
                $type = TicketType::factory()->forEvent($event)->create([
                    'capacity' => 1_000,
                    'sold_count' => 0,
                    'reserved_count' => 0,
                ]);

                // Independently compute expected per-day tickets & revenue,
                // keyed by Y-m-d.
                $expectedTickets = [];
                $expectedRevenue = [];

                foreach ($orderSeeds as $seed) {
                    [$statusIndex, $dayIndex, $fulfilledNull, $validCount, $voidedCount, $orderTotal] = $seed;

                    $status = self::CONFIRMED_STATUSES[$statusIndex];
                    $dayOffset = self::DAY_OFFSETS[$dayIndex];
                    $fulfilledNull = (bool) $fulfilledNull;
                    $validCount = (int) $validCount;
                    $voidedCount = (int) $voidedCount;
                    $orderTotal = (int) $orderTotal;

                    // The day this order lands on. When fulfilled_at is null the
                    // service falls back to created_at, so pin both to the same
                    // day at a fixed time (noon) to avoid ambiguity.
                    $moment = now()->subDays($dayOffset)->setTime(12, 0, 0);
                    $day = $moment->format('Y-m-d');

                    $order = Order::factory()->forEvent($event)->create([
                        'order_reference' => strtoupper(Str::random(12)),
                        'status' => $status,
                        'ticket_subtotal_minor' => $orderTotal,
                        'booking_fee_minor' => 0,
                        'application_fee_minor' => 0,
                        'order_total_minor' => $orderTotal,
                        'created_at' => $moment,
                        'fulfilled_at' => $fulfilledNull ? null : $moment,
                    ]);

                    if ($validCount > 0) {
                        Ticket::factory()->forOrder($order)->forTicketType($type)
                            ->count($validCount)->create();
                    }

                    if ($voidedCount > 0) {
                        Ticket::factory()->forOrder($order)->forTicketType($type)
                            ->voided()->count($voidedCount)->create();
                    }

                    $expectedTickets[$day] = ($expectedTickets[$day] ?? 0) + $validCount;
                    $expectedRevenue[$day] = ($expectedRevenue[$day] ?? 0) + $orderTotal;
                }

                $report = app(EventReportService::class)->for($event);
                $salesByDay = $report->salesByDay;

                // Every expected day must appear exactly once with matching
                // sums, and no unexpected day may appear.
                $this->assertSame(
                    count($expectedTickets),
                    count($salesByDay),
                    'salesByDay must produce exactly one row per confirmed-order day.'
                );

                $seenDays = [];
                $previousDay = null;

                foreach ($salesByDay as $row) {
                    $day = $row['day'];
                    $seenDays[] = $day;

                    $this->assertArrayHasKey(
                        $day,
                        $expectedTickets,
                        "salesByDay reported an unexpected day {$day}."
                    );

                    $this->assertSame(
                        $expectedTickets[$day],
                        $row['tickets'],
                        "tickets for {$day} must equal the valid-ticket sum over that day's confirmed orders."
                    );

                    $this->assertSame(
                        $expectedRevenue[$day],
                        $row['revenue_minor'],
                        "revenue_minor for {$day} must equal the order_total_minor sum over that day's confirmed orders."
                    );

                    // Chronological ordering: each day is >= the previous one.
                    if ($previousDay !== null) {
                        $this->assertTrue(
                            strcmp($previousDay, $day) < 0,
                            "salesByDay rows must be in strictly ascending day order ({$previousDay} then {$day})."
                        );
                    }

                    $previousDay = $day;
                }

                // No expected day may be missing (guards against duplicates
                // masking a missing day when counts happen to match).
                $this->assertEqualsCanonicalizing(
                    array_keys($expectedTickets),
                    $seenDays,
                    'salesByDay must cover exactly the set of confirmed-order days.'
                );
            });
    }
}
