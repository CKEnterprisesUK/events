<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke coverage for the UI/UX refresh: the key organiser pages must render
 * (no runtime Blade/view errors) and the navigation acceptance criteria must
 * hold — one unified sidebar, an event-context nav inside an event, no second
 * floating event menu.
 *
 * These are presentation assertions only; the refresh changed no backend
 * behaviour, permissions, routes or accounting.
 */
class OrganiserUiRefreshSmokeTest extends TestCase
{
    use RefreshDatabase;

    private function ownerAndEvent(): array
    {
        $owner = User::factory()->owner()->create();
        $event = Event::factory()->for(Company::find($owner->company_id))->create();
        TicketType::factory()->forEvent($event)->create();

        return [$owner, $event];
    }

    public function test_core_dashboard_pages_render(): void
    {
        [$owner] = $this->ownerAndEvent();

        foreach ([
            route('dashboard.home'),
            route('dashboard.events.index'),
            route('dashboard.orders.index'),
            route('dashboard.customers.index'),
            route('dashboard.reports.index'),
            route('dashboard.branding.edit'),
        ] as $url) {
            $this->actingAs($owner)->get($url)->assertOk();
        }
    }

    public function test_event_pages_render_and_use_a_single_event_context_nav(): void
    {
        [$owner, $event] = $this->ownerAndEvent();

        $response = $this->actingAs($owner)->get(route('dashboard.events.show', $event));
        $response->assertOk();

        // The event-context sidebar is present (back link + grouped sections).
        $response->assertSee('All events', false);
        $response->assertSee($event->name, false);

        // The old second floating event nav column must be gone entirely.
        $response->assertDontSee('event-manage__nav', false);
        $response->assertDontSee('class="section-nav"', false);

        // Renamed sidebar items per the design direction.
        $response->assertSee('>Location<', false);
        $response->assertSee('>Reports<', false);

        // Every event section screen still renders.
        foreach ([
            'dashboard.events.show',
            'dashboard.events.location',
            'dashboard.events.tickets',
            'dashboard.events.share',
            'dashboard.events.orders',
            'dashboard.events.history',
            'dashboard.events.report',
        ] as $route) {
            $this->actingAs($owner)->get(route($route, $event))->assertOk();
        }
    }

    public function test_overview_carries_setup_checklist_and_details_form(): void
    {
        [$owner, $event] = $this->ownerAndEvent();

        $response = $this->actingAs($owner)->get(route('dashboard.events.show', $event));
        $response->assertOk();

        // Setup checklist moved inline onto the Overview.
        $response->assertSee('Event setup', false);
        // Details editing lives in its dedicated section, still posting to update.
        $response->assertSee('id="event-details"', false);
        $response->assertSee(route('dashboard.events.update', $event), false);
    }

    public function test_events_list_has_functional_columns(): void
    {
        [$owner, $event] = $this->ownerAndEvent();

        $response = $this->actingAs($owner)->get(route('dashboard.events.index'));
        $response->assertOk();
        $response->assertSee('Tickets sold', false);
        $response->assertSee('Date &amp; time', false);
    }
}
