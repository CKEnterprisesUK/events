<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 6.1 — the events table/model, EventController (create/update/
 * publish) scoped to the Company, and the public publish gating.
 *
 * Requirements: 5.1 (create scoped to the Admin's Company), 5.2 (overall
 * capacity, including NULL = unlimited), 5.3 (update persists), 5.4 (publish
 * makes the event page available), 5.5 (unpublished blocks Customer
 * view/purchase). Cross-Company isolation (1.5) and Admin gating (3.4, 3.7) are
 * exercised alongside.
 */
class EventManagementTest extends TestCase
{
    use RefreshDatabase;

    // ---- Model / persistence -------------------------------------------------

    public function test_event_persists_all_design_columns_with_defaults(): void
    {
        $company = Company::factory()->create();

        $event = Event::factory()->for($company)->create([
            'name' => 'Summer Gala',
            'capacity' => 250,
            'is_published' => false,
        ]);

        $fresh = $event->fresh();

        $this->assertSame($company->id, $fresh->company_id);
        $this->assertSame('Summer Gala', $fresh->name);
        $this->assertSame(250, $fresh->capacity);
        // Requirement 5.5 — events default to unpublished.
        $this->assertFalse($fresh->isPublished());
    }

    public function test_event_capacity_may_be_null_for_unlimited(): void
    {
        // Requirement 5.2 — capacity is optional (NULL = unlimited).
        $event = Event::factory()->unlimitedCapacity()->create();

        $this->assertNull($event->fresh()->capacity);
    }

    public function test_event_round_trips_branding_overrides(): void
    {
        // Requirement 7.5 — Event-level branding overrides.
        $event = Event::factory()->create([
            'primary_colour' => '#123456',
            'logo_path' => 'logos/event.png',
            'ticket_field_defs' => ['fields' => [['key' => 'row', 'label' => 'Row']]],
        ]);

        $fresh = $event->fresh();

        $this->assertSame('#123456', $fresh->primary_colour);
        $this->assertSame('logos/event.png', $fresh->logo_path);
        $this->assertSame(
            ['fields' => [['key' => 'row', 'label' => 'Row']]],
            $fresh->ticket_field_defs
        );
    }

    // ---- Dashboard CRUD (Admin) ---------------------------------------------

    public function test_admin_creates_an_event_scoped_to_their_company(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->post('/dashboard/events', [
            'name' => 'Autumn Fair',
            'venue' => 'Town Hall',
            'capacity' => 500,
            'location_mode' => Event::LOCATION_IN_PERSON,
        ]);

        // Requirement 5.1 — persisted, scoped to the Admin's Company.
        $this->assertDatabaseHas('events', [
            'company_id' => $admin->company_id,
            'name' => 'Autumn Fair',
            'capacity' => 500,
            'is_published' => false,
        ]);

        $event = Event::withoutGlobalScopes()->where('name', 'Autumn Fair')->first();
        $response->assertRedirect(route('dashboard.events.show', $event));
    }

    public function test_admin_creates_an_event_with_unlimited_capacity(): void
    {
        $admin = User::factory()->admin()->create();

        // Requirement 5.2 — capacity omitted => NULL (unlimited).
        $this->actingAs($admin)->post('/dashboard/events', [
            'name' => 'Open Day',
            'location_mode' => Event::LOCATION_IN_PERSON,
        ])->assertRedirect();

        $this->assertDatabaseHas('events', [
            'company_id' => $admin->company_id,
            'name' => 'Open Day',
            'capacity' => null,
        ]);
    }

    public function test_admin_updates_an_event_and_the_details_persist(): void
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))->create([
            'name' => 'Old Name',
            'capacity' => 100,
        ]);

        // Requirement 5.3 — updated details persist.
        $this->actingAs($admin)->put("/dashboard/events/{$event->id}", [
            'name' => 'New Name',
            'capacity' => 320,
            'location_mode' => Event::LOCATION_IN_PERSON,
        ])->assertRedirect(route('dashboard.events.show', $event));

        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'name' => 'New Name',
            'capacity' => 320,
        ]);
    }

    public function test_admin_publishes_and_unpublishes_an_event(): void
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))
            ->unpublished()
            ->create();

        // Publish gating requires a start date (set by the factory default) and
        // at least one ticket type; a FREE ticket type also keeps the payments
        // gate satisfied without needing a connected Stripe account.
        TicketType::factory()->forEvent($event)->free()->create();

        // Requirement 5.4 — publishing makes the event available.
        $this->actingAs($admin)->post("/dashboard/events/{$event->id}/publish")
            ->assertRedirect(route('dashboard.events.show', $event));
        $this->assertTrue($event->fresh()->isPublished());

        // Requirement 5.5 — unpublishing blocks Customer view again.
        $this->actingAs($admin)->post("/dashboard/events/{$event->id}/unpublish")
            ->assertRedirect(route('dashboard.events.show', $event));
        $this->assertFalse($event->fresh()->isPublished());
    }

    public function test_create_requires_a_name(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->from('/dashboard/events')
            ->post('/dashboard/events', ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('events', 0);
    }

    // ---- Tenant isolation ----------------------------------------------------

    public function test_admin_cannot_view_or_update_another_companys_event(): void
    {
        $admin = User::factory()->admin()->create();
        $otherEvent = Event::factory()->create(); // different Company

        // Requirement 1.5 — cross-Company row is not found under the scope (404).
        $this->actingAs($admin)->get("/dashboard/events/{$otherEvent->id}")
            ->assertNotFound();

        $this->actingAs($admin)->put("/dashboard/events/{$otherEvent->id}", [
            'name' => 'Hijacked',
        ])->assertNotFound();

        // Untouched.
        $this->assertDatabaseHas('events', [
            'id' => $otherEvent->id,
            'name' => $otherEvent->name,
        ]);
    }

    public function test_index_lists_only_the_authenticated_companys_events(): void
    {
        $admin = User::factory()->admin()->create();
        Event::factory()->for(Company::find($admin->company_id))->create(['name' => 'Mine']);
        Event::factory()->create(['name' => 'Theirs']); // other Company

        $response = $this->actingAs($admin)->get('/dashboard/events');

        $response->assertStatus(200);
        $response->assertSee('Mine');
        $response->assertDontSee('Theirs');
    }

    // ---- Role gating ---------------------------------------------------------

    public function test_non_admin_roles_cannot_manage_events(): void
    {
        foreach ([
            User::factory()->accountant()->create(),
            User::factory()->scanner()->create(),
        ] as $user) {
            // Requirements 3.4, 3.7 — only Admin manages events.
            $this->actingAs($user)->post('/dashboard/events', [
                'name' => 'Nope',
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('events', 0);
    }

    // ---- Public publish gating ----------------------------------------------

    public function test_published_event_page_is_available_to_customers(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create(['name' => 'Public Gala']);

        // Requirement 5.4 — published event page is available.
        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertStatus(200);
        $response->assertSee('Public Gala');
    }

    public function test_unpublished_event_page_is_not_served_to_customers(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->unpublished()->create();

        // Requirement 5.5 — unpublished events block Customer view/purchase.
        $this->get("/{$company->slug}/{$event->id}")->assertNotFound();
    }

    public function test_event_page_is_scoped_to_the_resolved_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        // A published event that belongs to company B, requested under A's slug.
        $eventB = Event::factory()->for($companyB)->published()->create();

        // Requirement 1.5 — foreign event id under A's slug is not found.
        $this->get("/{$companyA->slug}/{$eventB->id}")->assertNotFound();
    }
}
