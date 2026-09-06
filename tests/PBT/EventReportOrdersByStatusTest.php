<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Services\EventReportService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Property-based test for the orders-by-status breakdown computed by
 * {@see EventReportService} (feature Property 10).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, running against the real MySQL
 * test database.
 *
 * Over generated populations of an Event's Orders spread across the five
 * reported states, the property asserts that
 * `EventReportService::for($event)->ordersByStatus` reports, for each bucket,
 * a count equal to the number of the Event's Orders in that status — where the
 * `comp` bucket maps to {@see Order::STATUS_FREE_CONFIRMED}. The breakdown is
 * exhaustive: every one of the five buckets (paid, reserved, refunded,
 * cancelled, comp) is asserted against an independently generated count.
 * (Requirement 6.4)
 *
 * The EventReportService queries Orders, which are tenant-scoped, so the
 * Event's Company is established as the active tenant for each iteration
 * (mirroring EventReportAccountingTest) and cleared in tearDown.
 */
class EventReportOrdersByStatusTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 10: Orders-by-status breakdown is exhaustive — for any generated
     * population of an Event's Orders across the paid, reserved, refunded,
     * cancelled, and free_confirmed states, EventReportService::for()->
     * ordersByStatus reports for each bucket a count equal to the number of the
     * Event's Orders in that status, with `comp` mapping to free_confirmed.
     *
     * **Validates: Requirements 6.4**
     */
    // Feature: event-management-and-reporting, Property 10: Orders-by-status breakdown is exhaustive
    public function test_orders_by_status_breakdown_is_exhaustive(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // One count per status: paid, reserved, refunded, cancelled,
            // free_confirmed. Kept small (0–4 each) to keep iterations fast.
            Generator\choose(0, 4),
            Generator\choose(0, 4),
            Generator\choose(0, 4),
            Generator\choose(0, 4),
            Generator\choose(0, 4),
        )
            ->then(function (
                int $paidCount,
                int $reservedCount,
                int $refundedCount,
                int $cancelledCount,
                int $freeConfirmedCount
            ): void {
                // Build a single Company/Event and establish the Company as the
                // active tenant so the tenant-scoped Order queries inside
                // EventReportService resolve against this Company's rows.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->unlimitedCapacity()->create();

                app(TenantContext::class)->setCompany($company);

                $countsByStatus = [
                    Order::STATUS_PAID => $paidCount,
                    Order::STATUS_RESERVED => $reservedCount,
                    Order::STATUS_REFUNDED => $refundedCount,
                    Order::STATUS_CANCELLED => $cancelledCount,
                    Order::STATUS_FREE_CONFIRMED => $freeConfirmedCount,
                ];

                foreach ($countsByStatus as $status => $count) {
                    for ($i = 0; $i < $count; $i++) {
                        Order::factory()->forEvent($event)->create([
                            'order_reference' => strtoupper(Str::random(12)),
                            'status' => $status,
                        ]);
                    }
                }

                $report = app(EventReportService::class)->for($event);

                $this->assertSame(
                    $paidCount,
                    $report->ordersByStatus['paid'],
                    "ordersByStatus['paid'] must equal the number of paid orders."
                );

                $this->assertSame(
                    $reservedCount,
                    $report->ordersByStatus['reserved'],
                    "ordersByStatus['reserved'] must equal the number of reserved orders."
                );

                $this->assertSame(
                    $refundedCount,
                    $report->ordersByStatus['refunded'],
                    "ordersByStatus['refunded'] must equal the number of refunded orders."
                );

                $this->assertSame(
                    $cancelledCount,
                    $report->ordersByStatus['cancelled'],
                    "ordersByStatus['cancelled'] must equal the number of cancelled orders."
                );

                $this->assertSame(
                    $freeConfirmedCount,
                    $report->ordersByStatus['comp'],
                    "ordersByStatus['comp'] must equal the number of free_confirmed orders."
                );
            });
    }
}
