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
 * The tabbed Reports & Analytics page: ticket-type breakdown, previous-period
 * comparison, refunds summary and the attendance links.
 */
class ReportAnalyticsTabsTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(Event $event, TicketType $type, int $subtotal, int $quantity, int $daysAgo = 0): Order
    {
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => $subtotal,
            'application_fee_minor' => 0,
            'order_total_minor' => $subtotal,
            'fulfilled_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo),
        ]);
        Ticket::factory()->forOrder($order)->forTicketType($type)->count($quantity)->create();

        return $order;
    }

    public function test_unknown_tab_falls_back_to_sales(): void
    {
        $accountant = User::factory()->accountant()->create();

        $this->actingAs($accountant)
            ->get(route('dashboard.reports.index', ['tab' => 'nonsense']))
            ->assertOk()
            ->assertViewHas('tab', 'sales');
    }

    public function test_ticket_tab_breaks_sales_down_by_ticket_type(): void
    {
        $accountant = User::factory()->accountant()->create();
        $event = Event::factory()->for(Company::find($accountant->company_id))->create(['capacity' => 1000]);
        $adult = TicketType::factory()->forEvent($event)->create(['name' => 'Adult', 'price_minor' => 2_000, 'capacity' => 100, 'sold_count' => 3]);
        $child = TicketType::factory()->forEvent($event)->create(['name' => 'Child', 'price_minor' => 1_000, 'capacity' => 100, 'sold_count' => 1]);
        $this->paidOrder($event, $adult, 6_000, 3);
        $this->paidOrder($event, $child, 1_000, 1);

        $rows = collect(
            $this->actingAs($accountant)
                ->get(route('dashboard.reports.index', ['tab' => 'tickets']))
                ->assertOk()
                ->viewData('ticketTypes')
        )->keyBy('type_name');

        $this->assertSame(3, $rows['Adult']['sold']);
        $this->assertSame(6_000, $rows['Adult']['revenue_minor']);
        $this->assertSame(97, $rows['Adult']['remaining']);
        $this->assertSame(3.0, $rows['Adult']['sell_through_percent']);
        $this->assertSame(1, $rows['Child']['sold']);
    }

    public function test_selecting_an_event_lists_its_unsold_ticket_types(): void
    {
        $accountant = User::factory()->accountant()->create();
        $event = Event::factory()->for(Company::find($accountant->company_id))->create(['capacity' => 1000]);
        TicketType::factory()->forEvent($event)->create(['name' => 'VIP', 'capacity' => 10]);

        $rows = $this->actingAs($accountant)
            ->get(route('dashboard.reports.index', ['tab' => 'tickets', 'event' => $event->id]))
            ->viewData('ticketTypes');

        $this->assertSame('VIP', $rows[0]['type_name']);
        $this->assertSame(0, $rows[0]['sold']);
    }

    public function test_sales_tab_compares_with_the_previous_period(): void
    {
        $accountant = User::factory()->accountant()->create();
        $event = Event::factory()->for(Company::find($accountant->company_id))->create(['capacity' => 1000]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000]);
        $this->paidOrder($event, $type, 10_000, 1, daysAgo: 1);
        $this->paidOrder($event, $type, 5_000, 1, daysAgo: 8);

        $comparison = $this->actingAs($accountant)
            ->get(route('dashboard.reports.index', [
                'from' => now()->subDays(6)->toDateString(),
                'to' => now()->toDateString(),
            ]))
            ->viewData('comparison');

        $this->assertSame(5_000, $comparison['deltas']['gross_sales_minor']['previous']);
        $this->assertSame(100.0, $comparison['deltas']['gross_sales_minor']['change']);
        $this->assertCount(7, $comparison['series']);
        $this->assertCount(7, $comparison['previous_series']);
    }

    public function test_open_ended_range_has_no_comparison(): void
    {
        $accountant = User::factory()->accountant()->create();

        $this->actingAs($accountant)
            ->get(route('dashboard.reports.index'))
            ->assertViewHas('comparison', null);
    }

    public function test_finance_tab_summarises_refunds(): void
    {
        $accountant = User::factory()->accountant()->create();
        $event = Event::factory()->for(Company::find($accountant->company_id))->create(['capacity' => 1000]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000]);
        $this->paidOrder($event, $type, 10_000, 1)->forceFill(['refunded_total_minor' => 2_500])->save();

        $refunds = $this->actingAs($accountant)
            ->get(route('dashboard.reports.index', ['tab' => 'finance']))
            ->viewData('refunds');

        $this->assertSame(1, $refunds['orders']);
        $this->assertSame(2_500, $refunds['amount_minor']);
    }

    public function test_attendance_tab_links_to_the_live_dashboard(): void
    {
        $accountant = User::factory()->accountant()->create();
        $event = Event::factory()->for(Company::find($accountant->company_id))->create(['capacity' => 100]);

        $this->actingAs($accountant)
            ->get(route('dashboard.reports.index', ['tab' => 'attendance']))
            ->assertOk()
            ->assertSee(route('dashboard.events.live', $event), false);
    }

    public function test_tabs_do_not_leak_other_companies_ticket_types(): void
    {
        $accountant = User::factory()->accountant()->create();
        $other = User::factory()->accountant()->create();
        $foreignEvent = Event::factory()->for(Company::find($other->company_id))->create(['capacity' => 100]);
        TicketType::factory()->forEvent($foreignEvent)->create(['name' => 'Foreign pass']);

        $this->actingAs($accountant)
            ->get(route('dashboard.reports.index', ['tab' => 'tickets']))
            ->assertDontSee('Foreign pass');
    }
}
