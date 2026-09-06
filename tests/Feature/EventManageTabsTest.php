<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-experience-polish — task 9.13
 *
 * Covers the tabbed manage-event page (the tab shell that renders all six
 * panels server-side) and the inline ticket-type / comp management that posts
 * to the existing controller routes.
 *
 * Requirements: 1.1, 1.2, 1.3, 1.6, 1.7, 1.8 (accessible tab shell, no-JS
 * reachability, manager-only, tenant isolation), 6.1, 6.2, 6.3, 6.6, 6.7
 * (inline ticket-type management), 7.3, 7.4 (comp issuance from the tab).
 *
 * The manage page is GET route('dashboard.events.show', $event) →
 * EventController@show, rendering dashboard.events.show (the tabbed shell).
 */
class EventManageTabsTest extends TestCase
{
    use RefreshDatabase;

    private function adminAndEvent(): array
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))->create();

        return [$admin, $event];
    }

    // ---- Tab shell rendering -------------------------------------------------

    public function test_manage_page_renders_the_six_tabs(): void
    {
        [$admin, $event] = $this->adminAndEvent();

        $response = $this->actingAs($admin)->get(route('dashboard.events.show', $event));

        $response->assertStatus(200);

        // Tab labels (Requirement 1.1).
        foreach (['Overview', 'Ticket types', 'Location', 'Share &amp; QR', 'Report', 'Orders'] as $label) {
            $response->assertSee($label, false);
        }

        // ARIA tab markup (Requirements 1.2, 1.8).
        $response->assertSee('role="tab"', false);
        $response->assertSee('aria-controls="panel-ticket-types"', false);

        // The six panels render server-side (Requirement 1.3).
        foreach ([
            'id="panel-overview"',
            'id="panel-ticket-types"',
            'id="panel-location"',
            'id="panel-share"',
            'id="panel-report"',
            'id="panel-orders"',
        ] as $panel) {
            $response->assertSee($panel, false);
        }
    }

    public function test_panels_are_visible_without_js(): void
    {
        [$admin, $event] = $this->adminAndEvent();
        // Give the event a ticket type so the ticket-types panel has content.
        $ticketType = TicketType::factory()->forEvent($event)->create(['name' => 'General Admission']);

        $response = $this->actingAs($admin)->get(route('dashboard.events.show', $event));

        $response->assertStatus(200);

        // Panels are rendered as tabpanels with no `hidden` attribute server-side;
        // JS adds `hidden` to inactive panels only. (Requirement 1.3)
        $response->assertSee('role="tabpanel"', false);

        // Content from multiple panels is present in the DOM simultaneously,
        // demonstrating no-JS reachability: the ticket type name (ticket-types
        // panel) AND the orders panel content both render. (Requirement 1.3)
        $response->assertSee('General Admission', false);
        $response->assertSee('id="panel-orders"', false);
    }

    public function test_ticket_type_and_comp_forms_post_to_existing_routes(): void
    {
        [$admin, $event] = $this->adminAndEvent();
        // The comp form only renders once the event has at least one ticket
        // type (otherwise the panel shows an "add a ticket type" prompt).
        TicketType::factory()->forEvent($event)->create();

        $response = $this->actingAs($admin)->get(route('dashboard.events.show', $event));

        $response->assertStatus(200);

        // Inline management posts to the existing routes (Requirements 6.1,
        // 6.2, 6.3, 7.3).
        $response->assertSee(route('dashboard.events.ticket-types.store', $event), false);
        $response->assertSee(route('dashboard.events.comp', $event), false);
    }

    // ---- Access control ------------------------------------------------------

    public function test_non_manager_gets_403(): void
    {
        // A scanner and an accountant are not managers of events. (Requirements
        // 1.6, 6.6)
        foreach ([
            User::factory()->scanner()->create(),
            User::factory()->accountant()->create(),
        ] as $user) {
            $event = Event::factory()->for(Company::find($user->company_id))->create();

            $this->actingAs($user)
                ->get(route('dashboard.events.show', $event))
                ->assertForbidden();
        }
    }

    public function test_foreign_company_event_404(): void
    {
        $admin = User::factory()->admin()->create();
        $otherEvent = Event::factory()->create(); // different Company

        // Cross-Company event is not found under the tenant scope. (Requirements
        // 1.7, 6.7, 7.4)
        $this->actingAs($admin)
            ->get(route('dashboard.events.show', $otherEvent))
            ->assertNotFound();
    }

    // ---- Inline management end-to-end ---------------------------------------

    public function test_admin_can_create_a_ticket_type_from_the_tab(): void
    {
        [$admin, $event] = $this->adminAndEvent();

        // Capped ticket type created via the tab's form -> existing store route.
        // (Requirements 6.1, 6.2, 6.3)
        $this->actingAs($admin)->post(
            route('dashboard.events.ticket-types.store', $event),
            [
                'name' => 'VIP',
                'price' => '25.00',
                'capacity_mode' => TicketType::MODE_CAPPED,
                'capacity' => 100,
                'sale_starts_at' => now()->subDay()->toDateTimeString(),
                'sale_ends_at' => now()->addMonth()->toDateTimeString(),
            ]
        )->assertRedirect(route('dashboard.events.ticket-types.index', $event));

        $this->assertDatabaseHas('ticket_types', [
            'event_id' => $event->id,
            'company_id' => $event->company_id,
            'name' => 'VIP',
            'price_minor' => 2500,
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => 100,
        ]);

        // Shared-pool ticket type: no capacity submitted -> stored capacity null.
        // (Requirement 6.3, 2.x)
        $this->actingAs($admin)->post(
            route('dashboard.events.ticket-types.store', $event),
            [
                'name' => 'Standing',
                'price' => '10.00',
                'capacity_mode' => TicketType::MODE_SHARED_POOL,
                'sale_starts_at' => now()->subDay()->toDateTimeString(),
                'sale_ends_at' => now()->addMonth()->toDateTimeString(),
            ]
        )->assertRedirect(route('dashboard.events.ticket-types.index', $event));

        $this->assertDatabaseHas('ticket_types', [
            'event_id' => $event->id,
            'name' => 'Standing',
            'price_minor' => 1000,
            'capacity_mode' => TicketType::MODE_SHARED_POOL,
            'capacity' => null,
        ]);
    }
}
