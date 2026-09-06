<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event management — dedicated per-section screens.
 *
 * The manage-event area is a set of dedicated screens, each its own URL, linked
 * from a per-event section sidebar (Overview / Where / Tickets / Share / Report
 * / Orders) with a pinned setup checklist:
 *   - Overview  GET dashboard.events.show      → EventController@show
 *   - Where     GET dashboard.events.location  → EventController@location
 *   - Tickets   GET dashboard.events.tickets   → EventController@tickets
 *   - Share     GET dashboard.events.share     → EventController@share
 *   - Orders    GET dashboard.events.orders    → EventController@orders
 *
 * Requirements: 1.6, 1.7 (manager-only, tenant isolation), 6.1, 6.2, 6.3, 6.6,
 * 6.7 (ticket-type management), 7.3, 7.4 (comp issuance), 4.1, 4.3 (location).
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

    // ---- Section navigation --------------------------------------------------

    public function test_overview_screen_renders_the_section_nav(): void
    {
        [$admin, $event] = $this->adminAndEvent();

        $response = $this->actingAs($admin)->get(route('dashboard.events.show', $event));

        $response->assertStatus(200);

        // Section labels (the sidebar).
        foreach (['Overview', 'Where', 'Tickets', 'Share', 'Report', 'Orders'] as $label) {
            $response->assertSee($label, false);
        }

        // Each section links to its own dedicated URL.
        $response->assertSee(route('dashboard.events.location', $event), false);
        $response->assertSee(route('dashboard.events.tickets', $event), false);
        $response->assertSee(route('dashboard.events.share', $event), false);
        $response->assertSee(route('dashboard.events.orders', $event), false);
    }

    public function test_each_section_screen_loads(): void
    {
        [$admin, $event] = $this->adminAndEvent();

        foreach ([
            'dashboard.events.show',
            'dashboard.events.location',
            'dashboard.events.tickets',
            'dashboard.events.share',
            'dashboard.events.orders',
        ] as $route) {
            $this->actingAs($admin)
                ->get(route($route, $event))
                ->assertStatus(200);
        }
    }

    public function test_tickets_screen_shows_ticket_type_and_comp_forms(): void
    {
        [$admin, $event] = $this->adminAndEvent();
        // The comp form only renders once the event has at least one ticket type.
        TicketType::factory()->forEvent($event)->create(['name' => 'General Admission']);

        $response = $this->actingAs($admin)->get(route('dashboard.events.tickets', $event));

        $response->assertStatus(200);
        $response->assertSee('General Admission', false);
        $response->assertSee(route('dashboard.events.ticket-types.store', $event), false);
        $response->assertSee(route('dashboard.events.comp', $event), false);
    }

    public function test_where_screen_shows_venue_and_location_form(): void
    {
        [$admin, $event] = $this->adminAndEvent();

        $response = $this->actingAs($admin)->get(route('dashboard.events.location', $event));

        $response->assertStatus(200);
        // The unified "Where" form posts to the dedicated location route.
        $response->assertSee(route('dashboard.events.location.update', $event), false);
        // Venue now lives here.
        $response->assertSee('name="venue"', false);
        // And the location type toggle.
        $response->assertSee('name="location_mode"', false);
    }

    // ---- Access control ------------------------------------------------------

    public function test_non_manager_gets_403(): void
    {
        foreach ([
            User::factory()->scanner()->create(),
            User::factory()->accountant()->create(),
        ] as $user) {
            $event = Event::factory()->for(Company::find($user->company_id))->create();

            foreach ([
                'dashboard.events.show',
                'dashboard.events.location',
                'dashboard.events.tickets',
                'dashboard.events.share',
                'dashboard.events.orders',
            ] as $route) {
                $this->actingAs($user)
                    ->get(route($route, $event))
                    ->assertForbidden();
            }
        }
    }

    public function test_foreign_company_event_404(): void
    {
        $admin = User::factory()->admin()->create();
        $otherEvent = Event::factory()->create(); // different Company

        $this->actingAs($admin)
            ->get(route('dashboard.events.show', $otherEvent))
            ->assertNotFound();
    }

    // ---- Inline management end-to-end ---------------------------------------

    public function test_admin_can_create_a_ticket_type_from_the_tickets_screen(): void
    {
        [$admin, $event] = $this->adminAndEvent();

        // Capped ticket type created via the tickets screen's form.
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
