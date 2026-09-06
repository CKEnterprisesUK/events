<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Feature: event-management-and-reporting — task 10.1.
 *
 * Covers EventReportController::show, served at the dashboard route
 * GET dashboard/events/{event}/report (name: dashboard.events.report). The
 * action is gated on ACTION_VIEW_REPORTS and renders the view
 * `dashboard.events.report` with `event` and `report` (an EventReport from the
 * shared EventReportService).
 *
 * Requirements:
 *   - 6.1 Report_Viewer requesting the report route sees the Event_Report_Page.
 *   - 6.2 The page shows the Event's core figures, from the shared service.
 *   - 6.7 A user lacking ACTION_VIEW_REPORTS is denied with HTTP 403.
 *   - 6.8 A foreign-company Event resolves as HTTP 404.
 *   - 6.9 No CSV export route/link is offered.
 *
 * NOTE ON THE AUTHORIZED CASE (Req 6.1, 6.2):
 * The Blade view `dashboard.events.report` is delivered by a later task (13.5)
 * and may not exist when this test first runs. A missing view surfaces as a 500
 * AFTER authorization and route-model binding have already succeeded, so it
 * would mask the auth/binding behaviour we actually want to assert here. To
 * stay robust across the sequencing, the authorized cases branch on whether the
 * view exists yet:
 *   - View present (after 13.5 lands): assert 200 + assertViewIs + the report
 *     view data (Req 6.1, 6.2) directly.
 *   - View absent (before 13.5): swap in a lightweight stub view for just this
 *     name so the render succeeds, then assert 200 + the same view data. The
 *     stub proves auth + binding passed and that the controller hands the view
 *     the `event` and `report`. Once 13.5 lands, the `exists()` branch takes
 *     over and this test tightens automatically with no edits needed.
 */
class EventReportPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an Event in the given Company with one Ticket_Type and a confirmed
     * paid order carrying `$qty` valid tickets, so the report has real figures.
     *
     * @return array{0: Event, 1: TicketType}
     */
    private function eventWithConfirmedSales(Company $company, int $capacity = 100): array
    {
        $event = Event::factory()->for($company)->create([
            'name' => 'Figures Fest',
            'capacity' => $capacity,
        ]);

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
            'price_minor' => 2_500,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => 5_000,
            'booking_fee_minor' => 0,
            'application_fee_minor' => 300,
            'order_total_minor' => 5_000,
            'fulfilled_at' => now(),
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count(2)->create();

        return [$event, $type];
    }

    /**
     * Register a minimal stub for the report view when the real one (task 13.5)
     * doesn't exist yet, so the authorized request can render and we can assert
     * on view data. No-op once the real view is present.
     */
    private function ensureReportViewRenderable(): void
    {
        if (View::exists('dashboard.events.report')) {
            return;
        }

        // The real view (task 13.5) isn't here yet. Register an extra view
        // location holding a trivial stub blade at the exact view path the
        // controller returns (dashboard/events/report.blade.php). This lets the
        // render step succeed so we can assert on the passed view data; it is a
        // no-op once the real view exists (the early return above).
        $root = sys_get_temp_dir().'/eventreport_stub_views';
        $eventsDir = $root.'/dashboard/events';
        if (! is_dir($eventsDir)) {
            mkdir($eventsDir, 0777, true);
        }
        file_put_contents($eventsDir.'/report.blade.php', 'Event Report Stub');

        $finder = app('view')->getFinder();
        $finder->prependLocation($root);
        $finder->flush();
    }

    // ---- Authorized access + figures (Req 6.1, 6.2) -------------------------

    public function test_accountant_sees_the_event_report_with_the_events_figures(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        [$event] = $this->eventWithConfirmedSales($company);

        $this->ensureReportViewRenderable();

        $response = $this->actingAs($accountant)->get(route('dashboard.events.report', $event));

        // Auth + tenant binding succeeded: not denied, not missing. (Req 6.1)
        $response->assertStatus(200);
        $response->assertViewIs('dashboard.events.report');
        $response->assertViewHas('event', fn ($viewEvent) => $viewEvent->is($event));

        // The report is an EventReport produced by the shared accounting
        // service, carrying this Event's figures. (Req 6.2, 6.6)
        //
        // We assert on the report the controller actually built DURING the
        // request (via viewData), because the shared EventReportService is
        // tenant-scoped: recomputing it here — after EnforceTenantScope has
        // cleared the request's tenant context — would query with no active
        // Company and return zeros. The controller's in-request figures are the
        // real source of truth, so we assert the concrete values the fixture
        // produces against them.
        $response->assertViewHas('report');
        $report = $response->viewData('report');
        $this->assertInstanceOf(\App\Services\Reporting\EventReport::class, $report);

        // One confirmed paid order, two valid tickets, £50.00 gross, less the
        // £3.00 application fee = £47.00 net. (Req 6.2, 6.6)
        $this->assertSame(1, $report->confirmedOrders);
        $this->assertSame(2, $report->ticketsSold);
        $this->assertSame(5_000, $report->grossRevenueMinor);
        $this->assertSame(5_000 - 300, $report->netToCompanyMinor);
        $this->assertSame(100, $report->capacity);
    }

    public function test_owner_can_also_view_the_event_report(): void
    {
        // The Owner holds the union of all Company actions, including
        // ACTION_VIEW_REPORTS. (Req 6.1)
        $owner = User::factory()->owner()->create();
        $company = Company::find($owner->company_id);
        [$event] = $this->eventWithConfirmedSales($company);

        $this->ensureReportViewRenderable();

        $response = $this->actingAs($owner)->get(route('dashboard.events.report', $event));

        $response->assertStatus(200);
        $response->assertViewIs('dashboard.events.report');
        $response->assertViewHas('event', fn ($viewEvent) => $viewEvent->is($event));
        $response->assertViewHas('report');
    }

    // ---- Authorization: non-report-viewers are denied (Req 6.7) -------------

    public function test_admin_and_scanner_are_forbidden_from_the_report(): void
    {
        // Admin/Scanner do not hold ACTION_VIEW_REPORTS. Authorization fails
        // BEFORE the view renders, so this is a clean 403 regardless of whether
        // the report view file exists yet. (Req 6.7)
        foreach ([
            User::factory()->admin()->create(),
            User::factory()->scanner()->create(),
        ] as $user) {
            $company = Company::find($user->company_id);
            $event = Event::factory()->for($company)->create();

            $this->actingAs($user)
                ->get(route('dashboard.events.report', $event))
                ->assertForbidden();
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $event = Event::factory()->create();

        $this->get(route('dashboard.events.report', $event))->assertRedirect('/login');
    }

    // ---- Tenant isolation: foreign Event 404s (Req 6.8) ---------------------

    public function test_foreign_company_event_returns_404(): void
    {
        // The accountant requests a report for an Event owned by another
        // Company. Route-model binding under the tenant scope finds no row and
        // 404s BEFORE the view renders. (Req 6.8)
        $accountant = User::factory()->accountant()->create();
        $foreignEvent = Event::factory()->create(); // different Company

        $this->actingAs($accountant)
            ->get(route('dashboard.events.report', $foreignEvent))
            ->assertNotFound();
    }

    // ---- No CSV export (Req 6.9) --------------------------------------------

    public function test_no_csv_export_route_exists(): void
    {
        // The report offers no CSV export anywhere: no named export route and no
        // write/verb variant of the report route. (Req 6.9)
        $routes = app('router')->getRoutes();

        $this->assertNull(
            $routes->getByName('dashboard.events.report.csv'),
            'No CSV export route should be registered.'
        );
        $this->assertNull(
            $routes->getByName('dashboard.events.report.export'),
            'No report export route should be registered.'
        );

        // The report route only responds to GET (and HEAD); other verbs are not
        // routed to it, so a POST/PUT/etc. is a 405 rather than an export path.
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->create();

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            $this->actingAs($accountant)
                ->{$verb}(route('dashboard.events.report', $event))
                ->assertStatus(405);
        }
    }
}
