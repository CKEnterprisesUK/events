<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the truthful net-payout surfacing (order total − platform fee − actual
 * Stripe fee) on the Accountant report, date-range filtering, and the PDF/CSV
 * exports. Complements {@see AccountantReportingTest}, which covers the base
 * figures and access control. (Truthful-payout + report export types)
 */
class ReportPayoutAndExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A confirmed paid Order with an explicit money snapshot (including the
     * actual Stripe fee) and `$qty` valid tickets, created `$daysAgo` days ago.
     */
    private function paidOrder(
        Event $event,
        TicketType $type,
        int $total,
        int $applicationFee,
        ?int $stripeFee,
        int $qty,
        int $daysAgo = 0,
    ): Order {
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => $total,
            'application_fee_minor' => $applicationFee,
            'order_total_minor' => $total,
            'stripe_fee_minor' => $stripeFee,
            'fulfilled_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo),
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return $order;
    }

    private function accountantWithEvent(): array
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0,
        ]);

        return [$accountant, $event, $type];
    }

    public function test_net_payout_subtracts_platform_and_stripe_fees(): void
    {
        [$accountant, $event, $type] = $this->accountantWithEvent();

        // Total 10000, platform fee 500, actual Stripe fee 170.
        $this->paidOrder($event, $type, 10_000, 500, 170, 2);

        $totals = $this->actingAs($accountant)->get('/dashboard/reports')->viewData('totals');

        $this->assertSame(500, $totals['application_fees_minor']);
        $this->assertSame(170, $totals['stripe_fees_minor']);
        // Net after platform fee only, then the truthful payout after Stripe too.
        $this->assertSame(10_000 - 500, $totals['net_to_company_minor']);
        $this->assertSame(10_000 - 500 - 170, $totals['net_payout_minor']);
    }

    public function test_uncaptured_stripe_fee_counts_as_zero_not_null(): void
    {
        [$accountant, $event, $type] = $this->accountantWithEvent();

        // Stripe fee not captured yet (null) — must not break the sum; payout
        // degrades to the platform-fee-only net rather than becoming null.
        $this->paidOrder($event, $type, 5_000, 250, null, 1);

        $totals = $this->actingAs($accountant)->get('/dashboard/reports')->viewData('totals');

        $this->assertSame(0, $totals['stripe_fees_minor']);
        $this->assertSame(5_000 - 250, $totals['net_payout_minor']);
    }

    public function test_date_range_filters_orders_by_created_at(): void
    {
        [$accountant, $event, $type] = $this->accountantWithEvent();

        // Inside the window (5 days ago) and outside it (40 days ago).
        $this->paidOrder($event, $type, 3_000, 150, 45, 1, daysAgo: 5);
        $this->paidOrder($event, $type, 9_000, 450, 155, 1, daysAgo: 40);

        $from = now()->subDays(10)->toDateString();
        $to = now()->toDateString();

        $totals = $this->actingAs($accountant)
            ->get('/dashboard/reports?from='.$from.'&to='.$to)
            ->viewData('totals');

        // Only the in-range order counts.
        $this->assertSame(1, $totals['orders']);
        $this->assertSame(3_000, $totals['gross_sales_minor']);
        $this->assertSame(45, $totals['stripe_fees_minor']);
        $this->assertSame(3_000 - 150 - 45, $totals['net_payout_minor']);
    }

    public function test_ranged_per_event_figures_respect_the_window(): void
    {
        [$accountant, $event, $type] = $this->accountantWithEvent();

        $this->paidOrder($event, $type, 3_000, 150, 45, 2, daysAgo: 2);
        $this->paidOrder($event, $type, 9_000, 450, 155, 3, daysAgo: 40);

        $perEvent = $this->actingAs($accountant)
            ->get('/dashboard/reports?from='.now()->subDays(10)->toDateString())
            ->viewData('perEvent');

        $this->assertCount(1, $perEvent);
        $row = $perEvent[0];
        $this->assertSame(3_000, $row['gross_sales_minor']);
        $this->assertSame(2, $row['tickets_sold']);
        $this->assertSame(45, $row['stripe_fees_minor']);
        $this->assertSame(3_000 - 150 - 45, $row['net_payout_minor']);
    }

    public function test_csv_export_succeeds_and_is_csv(): void
    {
        [$accountant, $event, $type] = $this->accountantWithEvent();
        $this->paidOrder($event, $type, 3_000, 150, 45, 1);

        $response = $this->actingAs($accountant)->get('/dashboard/reports/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_pdf_export_succeeds_and_is_pdf(): void
    {
        [$accountant, $event, $type] = $this->accountantWithEvent();
        $this->paidOrder($event, $type, 3_000, 150, 45, 1);

        $response = $this->actingAs($accountant)->get('/dashboard/reports/export/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower((string) $response->headers->get('content-type')));
    }

    public function test_exports_are_gated_like_the_report(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/dashboard/reports/export')->assertForbidden();
        $this->actingAs($admin)->get('/dashboard/reports/export/pdf')->assertForbidden();
    }

    public function test_super_admin_transactions_export_is_csv(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0,
        ]);
        $this->paidOrder($event, $type, 4_000, 200, 80, 1);

        $response = $this->actingAs($superAdmin)->get('/admin/transactions/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
