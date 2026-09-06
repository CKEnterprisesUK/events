<?php

namespace Tests\PBT;

use App\Http\Controllers\ReportController;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\EventReportService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * Property-based test asserting that the per-Event figures produced by the
 * company report ({@see ReportController}) agree with the single accounting
 * source of truth ({@see EventReportService}) for the same Event
 * (feature Property 12).
 *
 * Task 7 refactored {@see ReportController::perEventBreakdown()} to delegate
 * its shared accounting figures to {@see EventReportService}, so the two views
 * of the same Event can never diverge. This property exercises that guarantee
 * directly: over generated populations of confirmed and non-confirmed Orders
 * (with valid and voided Tickets and money) under a single Event/Company, it
 * builds the confirmed-orders collection the exact way `ReportController::index`
 * does (`Order::whereIn('status', CONFIRMED_STATUSES)->get()`), invokes the
 * private `perEventBreakdown()` via reflection to obtain the ACTUAL controller
 * row for the Event, and asserts the five shared fields equal the corresponding
 * figures from `EventReportService::for($event)`:
 *
 *   - row['orders']                  == report->confirmedOrders
 *   - row['tickets_sold']            == report->ticketsSold
 *   - row['order_total_minor']       == report->grossRevenueMinor
 *   - row['net_to_company_minor']    == report->netToCompanyMinor
 *   - row['application_fees_minor']  == report->grossRevenueMinor
 *                                       - report->netToCompanyMinor
 *
 * The controller output is read directly (via reflection on the real private
 * method) rather than reimplemented in the test, so any drift between the two
 * accounting paths would surface as a failure.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, running against the real MySQL
 * test database. Order/Ticket queries are tenant-scoped, so the Event's Company
 * is established as the active tenant for each iteration and cleared in
 * tearDown.
 *
 * **Validates: Requirements 6.6**
 */
class PerEventReportParityTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The Order statuses an iteration may assign — the two confirmed states
     * plus a representative set of non-confirmed states that must be excluded
     * from every figure.
     *
     * @var list<string>
     */
    private const STATUS_POOL = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
        Order::STATUS_RESERVED,
        Order::STATUS_REFUNDED,
        Order::STATUS_CANCELLED,
    ];

    /**
     * The confirmed statuses whose money and valid tickets count towards the
     * realised figures — mirrors ReportController/EventReportService exactly.
     *
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 12: Per-event figures agree with the company report — for any
     * generated population of an Event's Orders and Tickets, the per-Event row
     * ReportController computes for that Event carries exactly the shared
     * accounting figures EventReportService::for() reports for the same Event.
     *
     * **Validates: Requirements 6.6**
     */
    // Feature: event-management-and-reporting, Property 12: Per-event figures agree with the company report
    public function test_per_event_figures_agree_with_the_company_report(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // A small sequence of Order seeds — one per Order for this Event.
            // Keeping the population tiny (0–6 orders) keeps each of the 100
            // iterations fast while still exercising the confirmed/excluded mix.
            Generator\seq(Generator\tuple(
                // Index into STATUS_POOL (confirmed or excluded state).
                Generator\choose(0, count(self::STATUS_POOL) - 1),
                // Number of VALID tickets on this Order (0–4).
                Generator\choose(0, 4),
                // Number of VOIDED tickets on this Order (0–3) — never counted.
                Generator\choose(0, 3),
                // The Order's ticket subtotal in minor units.
                Generator\choose(0, 100_000),
                // The Order's booking fee in minor units.
                Generator\choose(0, 10_000),
                // The Order's application (platform) fee in minor units.
                Generator\choose(0, 20_000),
            )),
        )
            ->then(function (array $orderSeeds): void {
                // Cap the population at 6 orders to keep iterations fast.
                $orderSeeds = array_slice(array_values($orderSeeds), 0, 6);

                // Build a single Company/Event and establish the Company as the
                // active tenant so the tenant-scoped Order/Ticket queries inside
                // both the controller and the service resolve against this
                // Company's rows.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->unlimitedCapacity()->create();

                app(TenantContext::class)->setCompany($company);

                // One Ticket_Type for the Event; every Ticket hangs off it.
                $type = TicketType::factory()->forEvent($event)->create([
                    'capacity' => 1_000,
                    'sold_count' => 0,
                    'reserved_count' => 0,
                ]);

                $hasConfirmed = false;

                foreach ($orderSeeds as $seed) {
                    [$statusIndex, $validCount, $voidedCount, $subtotal, $bookingFee, $applicationFee] = $seed;

                    $status = self::STATUS_POOL[$statusIndex];
                    $subtotal = (int) $subtotal;
                    $bookingFee = (int) $bookingFee;
                    $applicationFee = (int) $applicationFee;
                    $validCount = (int) $validCount;
                    $voidedCount = (int) $voidedCount;

                    $orderTotal = $subtotal + $bookingFee;

                    $order = Order::factory()->forEvent($event)->create([
                        'order_reference' => strtoupper(Str::random(12)),
                        'status' => $status,
                        'ticket_subtotal_minor' => $subtotal,
                        'booking_fee_minor' => $bookingFee,
                        'application_fee_minor' => $applicationFee,
                        'order_total_minor' => $orderTotal,
                        'fulfilled_at' => now(),
                    ]);

                    if ($validCount > 0) {
                        Ticket::factory()->forOrder($order)->forTicketType($type)
                            ->count($validCount)->create();
                    }

                    if ($voidedCount > 0) {
                        Ticket::factory()->forOrder($order)->forTicketType($type)
                            ->voided()->count($voidedCount)->create();
                    }

                    if (in_array($status, self::CONFIRMED_STATUSES, true)) {
                        $hasConfirmed = true;
                    }
                }

                // Build the confirmed-orders collection the SAME way
                // ReportController::index does, then invoke the private
                // perEventBreakdown() via reflection to read the ACTUAL
                // controller output (not a reimplementation).
                /** @var Collection<int, Order> $confirmedOrders */
                $confirmedOrders = Order::query()
                    ->whereIn('status', self::CONFIRMED_STATUSES)
                    ->get();

                $controller = app(ReportController::class);

                $method = new ReflectionMethod(ReportController::class, 'perEventBreakdown');
                $method->setAccessible(true);

                /** @var list<array<string, int|string>> $rows */
                $rows = $method->invoke($controller, $confirmedOrders);

                // The service's view of the same Event.
                $report = app(EventReportService::class)->for($event);

                // Locate this Event's row in the controller breakdown.
                $row = collect($rows)->firstWhere('event_id', $event->id);

                if (! $hasConfirmed) {
                    // No confirmed orders => the Event contributes no row to the
                    // company report, and the service reports zero everything.
                    $this->assertNull(
                        $row,
                        'Events with no confirmed orders must be omitted from the company breakdown.'
                    );
                    $this->assertSame(0, $report->confirmedOrders);

                    return;
                }

                $this->assertNotNull(
                    $row,
                    'An Event with confirmed orders must appear in the company breakdown.'
                );

                // The five shared accounting fields must agree between the
                // controller row and the EventReportService figures.
                $this->assertSame(
                    $report->confirmedOrders,
                    $row['orders'],
                    'orders must equal report->confirmedOrders.'
                );

                $this->assertSame(
                    $report->ticketsSold,
                    $row['tickets_sold'],
                    'tickets_sold must equal report->ticketsSold.'
                );

                $this->assertSame(
                    $report->grossRevenueMinor,
                    $row['order_total_minor'],
                    'order_total_minor must equal report->grossRevenueMinor.'
                );

                $this->assertSame(
                    $report->netToCompanyMinor,
                    $row['net_to_company_minor'],
                    'net_to_company_minor must equal report->netToCompanyMinor.'
                );

                $this->assertSame(
                    $report->grossRevenueMinor - $report->netToCompanyMinor,
                    $row['application_fees_minor'],
                    'application_fees_minor must equal report->grossRevenueMinor - report->netToCompanyMinor.'
                );
            });
    }
}
