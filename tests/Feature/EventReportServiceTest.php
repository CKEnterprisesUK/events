<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\EventReportService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Example-based tests for {@see EventReportService} — the single accounting
 * source of truth for a single Event's figures.
 *
 * These concrete scenarios complement the property-based coverage in
 * {@see \Tests\PBT\EventReportAccountingTest}, pinning down the exact
 * accounting definitions and each aggregation with fixed, hand-computed
 * expectations:
 *
 *   - tickets sold counts only `valid` tickets on CONFIRMED orders;
 *   - gross = sum of `order_total_minor` over confirmed orders;
 *   - net   = gross less the sum of `application_fee_minor`;
 *   - utilisation is 'unlimited' for a null capacity, else round(sold/cap*100,1);
 *   - the per-type, by-status, and by-day breakdowns.
 *
 * The service queries tenant-scoped Orders/Tickets, so each scenario
 * establishes the Event's Company as the active tenant (cleared in tearDown).
 *
 * **Validates: Requirements 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 6.5**
 */
class EventReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Build a Company + Event and set the Company as the active tenant so the
     * service's tenant-scoped queries resolve against this Company's rows.
     *
     * @param  array<string, mixed>  $eventAttributes
     */
    private function makeEvent(array $eventAttributes = []): Event
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->create($eventAttributes);

        app(TenantContext::class)->setCompany($company);

        return $event;
    }

    /**
     * Create an Order for the Event with the given status/money and attach the
     * requested number of valid and voided tickets of $type.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(
        Event $event,
        TicketType $type,
        string $status,
        int $subtotal,
        int $applicationFee,
        int $validTickets = 0,
        int $voidedTickets = 0,
        array $attributes = [],
    ): Order {
        $order = Order::factory()->forEvent($event)->create(array_merge([
            'order_reference' => strtoupper(Str::random(12)),
            'status' => $status,
            'ticket_subtotal_minor' => $subtotal,
            'booking_fee_minor' => 0,
            'application_fee_minor' => $applicationFee,
            'order_total_minor' => $subtotal,
        ], $attributes));

        if ($validTickets > 0) {
            Ticket::factory()->forOrder($order)->forTicketType($type)
                ->count($validTickets)->create();
        }

        if ($voidedTickets > 0) {
            Ticket::factory()->forOrder($order)->forTicketType($type)
                ->voided()->count($voidedTickets)->create();
        }

        return $order;
    }

    /**
     * Scenario 1: a mix of confirmed (paid + free_confirmed) and non-confirmed
     * (reserved / refunded / cancelled) orders, with valid and voided tickets.
     * Only confirmed orders and their valid tickets contribute to the realised
     * figures. (Requirements 5.2, 5.3, 6.2)
     */
    public function test_confirmed_order_accounting_over_mixed_population(): void
    {
        $event = $this->makeEvent(['capacity' => null]);
        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => 2_000,
            'capacity' => 1_000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        // Confirmed: a paid order (3 valid + 1 voided) and a free_confirmed
        // order (2 valid). Both count towards realised figures.
        $this->makeOrder($event, $type, Order::STATUS_PAID, subtotal: 10_000, applicationFee: 1_500, validTickets: 3, voidedTickets: 1);
        $this->makeOrder($event, $type, Order::STATUS_FREE_CONFIRMED, subtotal: 0, applicationFee: 0, validTickets: 2);

        // Non-confirmed: contribute NOTHING even though they carry money and
        // valid tickets.
        $this->makeOrder($event, $type, Order::STATUS_RESERVED, subtotal: 7_000, applicationFee: 900, validTickets: 4);
        $this->makeOrder($event, $type, Order::STATUS_REFUNDED, subtotal: 5_000, applicationFee: 600, validTickets: 2);
        $this->makeOrder($event, $type, Order::STATUS_CANCELLED, subtotal: 3_000, applicationFee: 300, validTickets: 1);

        $report = app(EventReportService::class)->for($event);

        // Two confirmed orders.
        $this->assertSame(2, $report->confirmedOrders);

        // Valid tickets on confirmed orders only: 3 (paid) + 2 (free) = 5.
        // The voided ticket on the paid order and the non-confirmed orders'
        // valid tickets are excluded.
        $this->assertSame(5, $report->ticketsSold);

        // Gross = 10_000 (paid) + 0 (free) = 10_000.
        $this->assertSame(10_000, $report->grossRevenueMinor);

        // Net = 10_000 − (1_500 + 0) = 8_500.
        $this->assertSame(8_500, $report->netToCompanyMinor);
    }

    /**
     * Scenario 2: utilisation is 'unlimited' when capacity is null, and the
     * rounded percentage otherwise. (Requirement 5.4)
     */
    public function test_utilisation_unlimited_for_null_capacity(): void
    {
        $event = $this->makeEvent(['capacity' => null]);
        TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        $report = app(EventReportService::class)->for($event);

        $this->assertSame('unlimited', $report->utilisation());
    }

    /**
     * Scenario 2 (cont.): a fixed-capacity event with a known tickets-sold
     * count reports round(sold / capacity * 100, 1). (Requirement 5.4)
     */
    public function test_utilisation_percentage_for_fixed_capacity(): void
    {
        // Capacity 8, with 3 valid tickets sold => round(3/8*100, 1) = 37.5.
        $event = $this->makeEvent(['capacity' => 8]);
        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => 1_000,
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        $this->makeOrder($event, $type, Order::STATUS_PAID, subtotal: 3_000, applicationFee: 0, validTickets: 3);

        $report = app(EventReportService::class)->for($event);

        $this->assertSame(3, $report->ticketsSold);
        $this->assertSame(37.5, $report->utilisation());
    }

    /**
     * Scenario 3: per-Ticket_Type breakdown. Two types with distinct
     * price/capacity/sold_count/reserved_count. Each row reports sold (valid
     * tickets on confirmed orders), remaining (capacity − sold_count −
     * reserved_count), and revenue (sold * price_minor). (Requirement 6.3)
     */
    public function test_per_ticket_type_breakdown(): void
    {
        $event = $this->makeEvent(['capacity' => null]);

        $general = TicketType::factory()->forEvent($event)->create([
            'name' => 'General',
            'price_minor' => 2_500,
            'capacity' => 100,
            'sold_count' => 30,
            'reserved_count' => 5,
        ]);

        $vip = TicketType::factory()->forEvent($event)->create([
            'name' => 'VIP',
            'price_minor' => 10_000,
            'capacity' => 20,
            'sold_count' => 8,
            'reserved_count' => 2,
        ]);

        // A confirmed paid order carrying 4 General + 2 VIP valid tickets, plus
        // one voided General ticket that must not be counted.
        $order = Order::factory()->forEvent($event)->create([
            'order_reference' => strtoupper(Str::random(12)),
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => 30_000,
            'booking_fee_minor' => 0,
            'application_fee_minor' => 3_000,
            'order_total_minor' => 30_000,
        ]);
        Ticket::factory()->forOrder($order)->forTicketType($general)->count(4)->create();
        Ticket::factory()->forOrder($order)->forTicketType($general)->voided()->create();
        Ticket::factory()->forOrder($order)->forTicketType($vip)->count(2)->create();

        $report = app(EventReportService::class)->for($event);

        $rows = collect($report->perTicketType)->keyBy('type_id');

        $generalRow = $rows->get($general->id);
        $this->assertSame('General', $generalRow['name']);
        $this->assertSame(4, $generalRow['sold']);
        // remaining = 100 − 30 − 5 = 65.
        $this->assertSame(65, $generalRow['remaining']);
        // revenue = 4 * 2_500 = 10_000.
        $this->assertSame(10_000, $generalRow['revenue_minor']);

        $vipRow = $rows->get($vip->id);
        $this->assertSame('VIP', $vipRow['name']);
        $this->assertSame(2, $vipRow['sold']);
        // remaining = 20 − 8 − 2 = 10.
        $this->assertSame(10, $vipRow['remaining']);
        // revenue = 2 * 10_000 = 20_000.
        $this->assertSame(20_000, $vipRow['revenue_minor']);
    }

    /**
     * Scenario 4: orders-by-status breakdown across the five reported states.
     * `comp` maps to free_confirmed; counts are over ALL of the Event's orders.
     * (Requirement 6.4)
     */
    public function test_orders_by_status_breakdown(): void
    {
        $event = $this->makeEvent(['capacity' => null]);
        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => 1_000,
            'capacity' => 1_000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        // 2 paid, 3 reserved, 1 refunded, 2 cancelled, 1 free_confirmed (comp).
        $this->makeOrder($event, $type, Order::STATUS_PAID, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_PAID, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_RESERVED, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_RESERVED, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_RESERVED, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_REFUNDED, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_CANCELLED, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_CANCELLED, 1_000, 0);
        $this->makeOrder($event, $type, Order::STATUS_FREE_CONFIRMED, 0, 0);

        $report = app(EventReportService::class)->for($event);

        $this->assertSame(2, $report->ordersByStatus['paid']);
        $this->assertSame(3, $report->ordersByStatus['reserved']);
        $this->assertSame(1, $report->ordersByStatus['refunded']);
        $this->assertSame(2, $report->ordersByStatus['cancelled']);
        $this->assertSame(1, $report->ordersByStatus['comp']);
    }

    /**
     * Scenario 5: sales-by-day trend. Confirmed orders across two distinct
     * fulfilled_at days, plus one confirmed order with a null fulfilled_at that
     * falls back to its created_at. Each day carries its tickets/revenue, and
     * days are returned in chronological order. (Requirement 6.5)
     */
    public function test_sales_by_day_trend_with_fulfilled_at_fallback(): void
    {
        $event = $this->makeEvent(['capacity' => null]);
        $type = TicketType::factory()->forEvent($event)->create([
            'price_minor' => 1_000,
            'capacity' => 1_000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        $dayOne = '2024-01-10';
        $dayTwo = '2024-01-12';
        $dayThree = '2024-01-15';

        // Day one: one confirmed order fulfilled on 2024-01-10, 2 valid tickets,
        // revenue 4_000.
        $this->makeOrder(
            $event, $type, Order::STATUS_PAID, subtotal: 4_000, applicationFee: 0, validTickets: 2,
            attributes: ['fulfilled_at' => $dayOne.' 09:00:00'],
        );

        // Day two: two confirmed orders fulfilled on 2024-01-12; 3 + 1 = 4
        // valid tickets, revenue 5_000 + 2_000 = 7_000.
        $this->makeOrder(
            $event, $type, Order::STATUS_PAID, subtotal: 5_000, applicationFee: 0, validTickets: 3,
            attributes: ['fulfilled_at' => $dayTwo.' 14:30:00'],
        );
        $this->makeOrder(
            $event, $type, Order::STATUS_FREE_CONFIRMED, subtotal: 2_000, applicationFee: 0, validTickets: 1,
            attributes: ['fulfilled_at' => $dayTwo.' 20:00:00'],
        );

        // Day three: a confirmed order with NULL fulfilled_at — must fall back
        // to created_at (set to 2024-01-15). 5 valid tickets, revenue 8_000.
        $this->makeOrder(
            $event, $type, Order::STATUS_PAID, subtotal: 8_000, applicationFee: 0, validTickets: 5,
            attributes: ['fulfilled_at' => null, 'created_at' => $dayThree.' 11:00:00'],
        );

        $report = app(EventReportService::class)->for($event);

        $this->assertCount(3, $report->salesByDay);

        // Chronological ordering by day.
        $this->assertSame(
            [$dayOne, $dayTwo, $dayThree],
            array_column($report->salesByDay, 'day'),
        );

        $byDay = collect($report->salesByDay)->keyBy('day');

        $this->assertSame(2, $byDay[$dayOne]['tickets']);
        $this->assertSame(4_000, $byDay[$dayOne]['revenue_minor']);

        $this->assertSame(4, $byDay[$dayTwo]['tickets']);
        $this->assertSame(7_000, $byDay[$dayTwo]['revenue_minor']);

        // The null-fulfilled_at order was grouped under its created_at day.
        $this->assertSame(5, $byDay[$dayThree]['tickets']);
        $this->assertSame(8_000, $byDay[$dayThree]['revenue_minor']);
    }
}
