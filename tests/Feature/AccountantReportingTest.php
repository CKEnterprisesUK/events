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
 * Feature: event-ticketing-platform — task 24.1.
 *
 * Covers ReportController: the Accountant's read-only reports and payouts,
 * gated on ACTION_VIEW_REPORTS, scoped to the Accountant's own Company, with
 * sales/revenue figures computed in integer minor units from the Company's
 * paid/confirmed Orders.
 *
 * Requirements:
 *   - 21.1 Accountant sees reports and payout information for their Company.
 *   - 21.2 Accountant cannot modify Events/Ticket_Types/Orders (auth error).
 *   - 21.3 All Accountant reports/payouts scoped to the Accountant's Company.
 *   - 3.5  Accountant role grants read-only reports/payouts.
 *   - 3.7  Denied actions leave data unchanged.
 *   - 1.5  Cross-Company isolation.
 */
class AccountantReportingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a confirmed Order in the given Company/Event with the given money
     * snapshot and `$qty` valid tickets.
     */
    private function confirmedOrder(
        Event $event,
        string $status,
        int $subtotal,
        int $bookingFee,
        int $applicationFee,
        int $qty,
        TicketType $type,
    ): Order {
        $order = Order::factory()->forEvent($event)->create([
            'status' => $status,
            'ticket_subtotal_minor' => $subtotal,
            'booking_fee_minor' => $bookingFee,
            'application_fee_minor' => $applicationFee,
            'order_total_minor' => $subtotal + $bookingFee,
            'fulfilled_at' => now(),
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return $order;
    }

    // ---- Access control (Property 6 / Requirement 3.5, 3.7) -----------------

    public function test_accountant_can_view_reports(): void
    {
        $accountant = User::factory()->accountant()->create();

        // Requirement 21.1 — reports available to the Accountant.
        $this->actingAs($accountant)->get('/dashboard/reports')->assertStatus(200);
    }

    public function test_non_accountant_roles_cannot_view_reports(): void
    {
        // Requirements 3.5, 3.7 — reports are the Accountant's read-only slice;
        // Admin/Scanner are not granted ACTION_VIEW_REPORTS. (The Owner, as the
        // account superuser, does hold every permission including reports.)
        foreach ([
            User::factory()->admin()->create(),
            User::factory()->scanner()->create(),
        ] as $user) {
            $this->actingAs($user)->get('/dashboard/reports')->assertForbidden();
        }
    }

    public function test_guests_are_redirected_from_reports(): void
    {
        $this->get('/dashboard/reports')->assertRedirect('/login');
    }

    // ---- Figures computed from paid/confirmed orders (21.1) -----------------

    public function test_totals_are_computed_from_paid_and_confirmed_orders_in_minor_units(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 1000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        // Paid order: subtotal 10000, booking fee 300 (pass_on), platform 500.
        $this->confirmedOrder($event, Order::STATUS_PAID, 10_000, 300, 500, 2, $type);
        // Free confirmed order: all money zero, 1 ticket.
        $this->confirmedOrder($event, Order::STATUS_FREE_CONFIRMED, 0, 0, 0, 1, $type);

        $response = $this->actingAs($accountant)->get('/dashboard/reports');
        $totals = $response->viewData('totals');

        $this->assertSame(2, $totals['orders']);
        $this->assertSame(3, $totals['tickets_sold']);
        $this->assertSame(10_000, $totals['gross_sales_minor']);
        $this->assertSame(300, $totals['booking_fees_minor']);
        $this->assertSame(500, $totals['application_fees_minor']);
        $this->assertSame(10_300, $totals['order_total_minor']);
        // Net to company = total collected minus the platform application fee.
        $this->assertSame(10_300 - 500, $totals['net_to_company_minor']);
    }

    public function test_reserved_expired_cancelled_and_refunded_orders_are_excluded(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 1000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        // One genuinely realised paid order.
        $this->confirmedOrder($event, Order::STATUS_PAID, 5_000, 0, 250, 1, $type);

        // Non-realised states must not contribute to the figures.
        foreach ([
            Order::STATUS_RESERVED,
            Order::STATUS_EXPIRED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_DISPUTED,
            Order::STATUS_VOIDED,
        ] as $status) {
            $this->confirmedOrder($event, $status, 9_999, 999, 999, 3, $type);
        }

        $totals = $this->actingAs($accountant)->get('/dashboard/reports')->viewData('totals');

        $this->assertSame(1, $totals['orders']);
        $this->assertSame(1, $totals['tickets_sold']);
        $this->assertSame(5_000, $totals['gross_sales_minor']);
        $this->assertSame(0, $totals['booking_fees_minor']);
        $this->assertSame(250, $totals['application_fees_minor']);
        $this->assertSame(5_000, $totals['order_total_minor']);
        $this->assertSame(4_750, $totals['net_to_company_minor']);
    }

    public function test_voided_tickets_do_not_count_towards_tickets_sold(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 1000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => 8_000,
            'order_total_minor' => 8_000,
            'fulfilled_at' => now(),
        ]);
        Ticket::factory()->forOrder($order)->forTicketType($type)->count(2)->create();
        Ticket::factory()->forOrder($order)->forTicketType($type)->voided()->count(1)->create();

        $totals = $this->actingAs($accountant)->get('/dashboard/reports')->viewData('totals');

        $this->assertSame(2, $totals['tickets_sold']);
    }

    // ---- Per-event breakdown (21.1) -----------------------------------------

    public function test_per_event_breakdown_reports_each_events_figures(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);

        $alpha = Event::factory()->for($company)->unlimitedCapacity()->create(['name' => 'Alpha Fest']);
        $beta = Event::factory()->for($company)->unlimitedCapacity()->create(['name' => 'Beta Bash']);

        $alphaType = TicketType::factory()->forEvent($alpha)->create(['capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0]);
        $betaType = TicketType::factory()->forEvent($beta)->create(['capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0]);

        $this->confirmedOrder($alpha, Order::STATUS_PAID, 3_000, 100, 150, 2, $alphaType);
        $this->confirmedOrder($beta, Order::STATUS_PAID, 7_000, 200, 350, 5, $betaType);

        $perEvent = $this->actingAs($accountant)->get('/dashboard/reports')->viewData('perEvent');

        $this->assertCount(2, $perEvent);

        $byId = collect($perEvent)->keyBy('event_id');

        $this->assertSame(3_000, $byId[$alpha->id]['gross_sales_minor']);
        $this->assertSame(150, $byId[$alpha->id]['application_fees_minor']);
        $this->assertSame(3_100 - 150, $byId[$alpha->id]['net_to_company_minor']);
        $this->assertSame(2, $byId[$alpha->id]['tickets_sold']);

        $this->assertSame(7_000, $byId[$beta->id]['gross_sales_minor']);
        $this->assertSame(350, $byId[$beta->id]['application_fees_minor']);
        $this->assertSame(7_200 - 350, $byId[$beta->id]['net_to_company_minor']);
        $this->assertSame(5, $byId[$beta->id]['tickets_sold']);
    }

    // ---- Company scoping / isolation (21.3, 1.5) ----------------------------

    public function test_reports_are_scoped_to_the_accountants_own_company(): void
    {
        $accountant = User::factory()->accountant()->create();
        $ownCompany = Company::find($accountant->company_id);
        $ownEvent = Event::factory()->for($ownCompany)->unlimitedCapacity()->create();
        $ownType = TicketType::factory()->forEvent($ownEvent)->create(['capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0]);
        $this->confirmedOrder($ownEvent, Order::STATUS_PAID, 4_000, 0, 200, 2, $ownType);

        // Another Company's confirmed sales must never leak into the report.
        $otherCompany = Company::factory()->create();
        $otherEvent = Event::factory()->for($otherCompany)->unlimitedCapacity()->create();
        $otherType = TicketType::factory()->forEvent($otherEvent)->create(['capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0]);
        $this->confirmedOrder($otherEvent, Order::STATUS_PAID, 99_000, 999, 9_999, 10, $otherType);

        $totals = $this->actingAs($accountant)->get('/dashboard/reports')->viewData('totals');

        // Only the accountant's Company figures appear. (Requirements 21.3, 1.5)
        $this->assertSame(1, $totals['orders']);
        $this->assertSame(2, $totals['tickets_sold']);
        $this->assertSame(4_000, $totals['gross_sales_minor']);
        $this->assertSame(200, $totals['application_fees_minor']);
        $this->assertSame(4_000, $totals['order_total_minor']);
    }

    public function test_report_renders_the_companys_figures(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create(['name' => 'Rendered Gala']);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0]);
        $this->confirmedOrder($event, Order::STATUS_PAID, 6_000, 0, 300, 1, $type);

        $response = $this->actingAs($accountant)->get('/dashboard/reports');

        $response->assertStatus(200);
        $response->assertSee('Rendered Gala');
        $response->assertSee('Reports');
    }

    // ---- Read-only: no mutation endpoints (21.2) ----------------------------

    public function test_report_route_exposes_no_write_endpoints(): void
    {
        $accountant = User::factory()->accountant()->create();

        // Only GET /dashboard/reports exists — write verbs are not routed.
        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->actingAs($accountant)->{$verb}('/dashboard/reports')
                ->assertStatus(405);
        }
    }

    public function test_accountant_cannot_modify_events_ticket_types_or_orders(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create(['name' => 'Untouched']);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000, 'sold_count' => 0, 'reserved_count' => 0]);
        $order = Order::factory()->forEvent($event)->create(['status' => Order::STATUS_PAID]);

        // Requirement 21.2 / 3.7 — every write action is denied for the
        // Accountant with an authorisation error, leaving data unchanged.
        $this->actingAs($accountant)->post('/dashboard/events', ['name' => 'Nope'])->assertForbidden();
        $this->actingAs($accountant)->put("/dashboard/events/{$event->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($accountant)->post("/dashboard/events/{$event->id}/publish")->assertForbidden();
        $this->actingAs($accountant)->post("/dashboard/events/{$event->id}/ticket-types", ['name' => 'X'])->assertForbidden();
        $this->actingAs($accountant)->post("/dashboard/orders/{$order->id}/cancel")->assertForbidden();
        $this->actingAs($accountant)->post("/dashboard/orders/{$order->id}/refund")->assertForbidden();

        // Data unchanged.
        $this->assertDatabaseHas('events', ['id' => $event->id, 'name' => 'Untouched']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
    }
}
