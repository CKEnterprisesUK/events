<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Task 12.7 — consolidated feature-test coverage for the public landing,
 * storefront and event pages. These complement (rather than duplicate)
 * LandingPageTest and StorefrontAndEventPageTest by covering the rendering
 * gaps the 12.7 acceptance criteria call out:
 *
 *  - 8.2 storefront lists the Company's PUBLISHED events (all of them, each
 *        linking to its event page; empty when none are published).
 *  - 8.3 the event page renders the event's Ticket_Types (all of them, with
 *        free vs paid pricing and the event's own details).
 *  - 5.5 unpublished events are never served publicly (excluded from the
 *        listing, and the event page is not reachable).
 */
class PublicPageRenderingTest extends TestCase
{
    use RefreshDatabase;

    // ---- 8.2 Storefront listing ---------------------------------------------

    public function test_storefront_lists_all_published_events_each_linking_to_its_page(): void
    {
        // Requirement 8.2 — every published Event is listed, and each links to
        // its own public event page under /{company-slug}/{event-id}.
        $company = Company::factory()->create();
        $first = Event::factory()->for($company)->published()->create(['name' => 'Spring Gala']);
        $second = Event::factory()->for($company)->published()->create(['name' => 'Autumn Fair']);

        $response = $this->get("/{$company->slug}");

        $response->assertOk();
        $response->assertSee('Spring Gala');
        $response->assertSee('Autumn Fair');
        $response->assertSee(url("{$company->slug}/{$first->id}"), false);
        $response->assertSee(url("{$company->slug}/{$second->id}"), false);
    }

    public function test_storefront_renders_empty_state_when_no_events_published(): void
    {
        // Requirement 8.2 — a resolved Company with no published Events still
        // renders its storefront (with an empty-state message), not a 404.
        $company = Company::factory()->create(['name' => 'Quiet Co']);
        Event::factory()->for($company)->unpublished()->create(['name' => 'Hidden Draft']);

        $response = $this->get("/{$company->slug}");

        $response->assertOk();
        $response->assertSee('Quiet Co');
        $response->assertDontSee('Hidden Draft');
        $response->assertSee('no events on sale');
    }

    // ---- 8.3 Event page renders its Ticket_Types ----------------------------

    public function test_event_page_lists_all_ticket_types_with_free_and_paid_pricing(): void
    {
        // Requirement 8.3 — the event page shows the Event with its available
        // Ticket_Types; free ones render "Free" and paid ones render a price.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create(['name' => 'Mixed Bill']);
        TicketType::factory()->forEvent($event)->free()->create(['name' => 'Community Pass']);
        TicketType::factory()->forEvent($event)->create([
            'name' => 'Premium Seat',
            'price_minor' => 2500,
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Mixed Bill');
        $response->assertSee('Community Pass');
        $response->assertSee('Free');
        $response->assertSee('Premium Seat');
        $response->assertSee('25.00');
    }

    public function test_event_page_renders_event_details(): void
    {
        // Requirement 8.3 — the specified Event's own details (name, venue,
        // description) are rendered on its public page.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create([
            'name' => 'Harbour Lights',
            'venue' => 'Pier 39 Hall',
            'description' => 'An evening by the water.',
        ]);
        TicketType::factory()->forEvent($event)->create();

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Harbour Lights');
        $response->assertSee('Pier 39 Hall');
        $response->assertSee('An evening by the water.');
    }

    public function test_event_page_renders_when_no_ticket_types_exist(): void
    {
        // Requirement 8.3 — a published Event with no Ticket_Types still renders
        // (with an empty-state message) rather than erroring.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create(['name' => 'Ticketless']);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Ticketless');
        $response->assertSee('No tickets are available');
    }

    // ---- 5.5 Unpublished / cross-company events are not served publicly ------

    public function test_unpublished_event_is_excluded_from_listing_and_page_not_served(): void
    {
        // Requirement 5.5 — an unpublished Event neither appears in the public
        // listing nor is reachable at its event page.
        $company = Company::factory()->create();
        $draft = Event::factory()->for($company)->unpublished()->create(['name' => 'Members Only']);

        $this->get("/{$company->slug}")->assertOk()->assertDontSee('Members Only');
        $this->get("/{$company->slug}/{$draft->id}")->assertNotFound();
    }

    public function test_event_page_404_for_event_belonging_to_another_company(): void
    {
        // Requirement 5.5 / 1.5 — a published Event from a different Company is
        // not served under this Company's slug.
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $foreignEvent = Event::factory()->for($other)->published()->create(['name' => 'Not Yours']);

        $this->get("/{$company->slug}/{$foreignEvent->id}")->assertNotFound();
    }
}
