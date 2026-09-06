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
 * Property-based test for the single-Event accounting definitions computed by
 * {@see EventReportService} (feature Property 7).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, running against the real MySQL
 * test database.
 *
 * Over generated populations of an Event's Orders (in a mix of confirmed and
 * non-confirmed statuses) and their Tickets (valid or voided), the property
 * asserts that `EventReportService::for($event)` computes exactly:
 *
 *   - ticketsSold        = count of `valid` Tickets on CONFIRMED Orders
 *                          (status paid or free_confirmed).
 *   - grossRevenueMinor  = sum of `order_total_minor` over confirmed Orders.
 *   - netToCompanyMinor  = that gross less the sum of `application_fee_minor`
 *                          over confirmed Orders.
 *
 * Non-confirmed Orders (reserved / refunded / cancelled) and voided Tickets
 * must contribute nothing. The expected figures are computed independently in
 * the test from the generated population, then compared against the service.
 *
 * The EventReportService queries Orders/Tickets, which are tenant-scoped, so
 * the Event's Company is established as the active tenant for each iteration
 * (mirroring EventPublishGateTest) and cleared in tearDown.
 */
class EventReportAccountingTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The Order statuses an iteration may assign — the two confirmed states
     * plus a representative set of non-confirmed states that must be excluded.
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
     * realised figures.
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
     * Property 7: Confirmed-order accounting definitions — for any generated
     * population of an Event's Orders and Tickets, EventReportService::for()
     * computes ticketsSold, grossRevenueMinor, and netToCompanyMinor over
     * exactly the confirmed Orders (paid / free_confirmed) and their valid
     * Tickets, excluding every non-confirmed Order and every voided Ticket.
     *
     * **Validates: Requirements 5.2, 5.3, 6.2, 6.6**
     */
    // Feature: event-management-and-reporting, Property 7: Confirmed-order accounting definitions
    public function test_confirmed_order_accounting_definitions(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // A small sequence of Order seeds — one per Order for this Event.
            // Keeping the population tiny (0–6 orders) keeps each of the 100
            // iterations fast while still exercising the mix.
            Generator\seq(Generator\tuple(
                // Index into STATUS_POOL (confirmed or excluded state).
                Generator\choose(0, count(self::STATUS_POOL) - 1),
                // Number of VALID tickets on this Order (0–4).
                Generator\choose(0, 4),
                // Number of VOIDED tickets on this Order (0–3) — never counted.
                Generator\choose(0, 3),
                // The Order's ticket subtotal in minor units.
                Generator\choose(0, 100_000),
                // The Order's application (platform) fee in minor units.
                Generator\choose(0, 20_000),
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

                $expectedTicketsSold = 0;
                $expectedGrossMinor = 0;
                $expectedNetMinor = 0;

                foreach ($orderSeeds as $seed) {
                    [$statusIndex, $validCount, $voidedCount, $subtotal, $applicationFee] = $seed;

                    $status = self::STATUS_POOL[$statusIndex];
                    $subtotal = (int) $subtotal;
                    $applicationFee = (int) $applicationFee;
                    $validCount = (int) $validCount;
                    $voidedCount = (int) $voidedCount;

                    $orderTotal = $subtotal;

                    $order = Order::factory()->forEvent($event)->create([
                        'order_reference' => strtoupper(Str::random(12)),
                        'status' => $status,
                        'ticket_subtotal_minor' => $subtotal,
                        'booking_fee_minor' => 0,
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

                    // Only confirmed orders (and only their valid tickets)
                    // contribute to the realised figures.
                    if (in_array($status, self::CONFIRMED_STATUSES, true)) {
                        $expectedTicketsSold += $validCount;
                        $expectedGrossMinor += $orderTotal;
                        $expectedNetMinor += $orderTotal - $applicationFee;
                    }
                }

                $report = app(EventReportService::class)->for($event);

                $this->assertSame(
                    $expectedTicketsSold,
                    $report->ticketsSold,
                    'ticketsSold must count only valid tickets on confirmed orders.'
                );

                $this->assertSame(
                    $expectedGrossMinor,
                    $report->grossRevenueMinor,
                    'grossRevenueMinor must sum order_total_minor over confirmed orders only.'
                );

                $this->assertSame(
                    $expectedNetMinor,
                    $report->netToCompanyMinor,
                    'netToCompanyMinor must be gross less application_fee_minor over confirmed orders.'
                );
            });
    }
}
