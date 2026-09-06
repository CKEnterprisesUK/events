<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-management-and-reporting
 *
 * Covers task 9.4 — publish gating on the dashboard EventController. An Event
 * may only be published once it has at least one Ticket_Type AND a non-null
 * `starts_at`; otherwise the publish is refused with a listed blocker message
 * and the Event stays unpublished. Unpublishing is unconditional. Writes are
 * gated by `ACTION_MANAGE_EVENTS` (non-Admins get 403) and are tenant-scoped
 * (foreign Events 404).
 *
 * Requirements: 1.1 (publish succeeds when ready), 1.2 (refused with blocker
 * list when a prerequisite is missing), 1.3 (unpublish is unconditional),
 * 1.5 (non-manager gets 403), 1.6 (foreign Event 404).
 */
class PublishGatingTest extends TestCase
{
    use RefreshDatabase;

    // ---- Publish succeeds when ready (Requirement 1.1) ----------------------

    public function test_publish_succeeds_with_a_ticket_type_and_a_start_date(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        // starts_at is set by the factory default; add a FREE ticket type so
        // both prerequisites are met without needing a connected Stripe account
        // (a paid ticket type would require payments to be set up first).
        $event = Event::factory()->for($company)->unpublished()->create([
            'name' => 'Ready Gala',
        ]);
        TicketType::factory()->forEvent($event)->free()->create();

        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/publish")
            ->assertRedirect(route('dashboard.events.show', $event));

        // Requirement 1.1 — the Event is now published.
        $this->assertTrue($event->fresh()->isPublished());

        // And the published Event page is served to Customers, mirroring the
        // existing publish behaviour test.
        $this->get("/{$company->slug}/{$event->id}")
            ->assertStatus(200)
            ->assertSee('Ready Gala');
    }

    // ---- Publish refused with blocker list (Requirement 1.2) ----------------

    public function test_publish_is_refused_when_no_ticket_type_exists(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        // Start date present, but no ticket type.
        $event = Event::factory()->for($company)->unpublished()->create();

        $response = $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/publish");

        $response->assertRedirect(route('dashboard.events.show', $event));
        // Requirement 1.2 — the Event stays unpublished.
        $this->assertFalse($event->fresh()->isPublished());
        // The refusal lists the ticket-type blocker.
        $response->assertSessionHas('publish_errors', function (array $errors): bool {
            return in_array('Add at least one ticket type.', $errors, true);
        });
    }

    public function test_publish_is_refused_when_start_date_is_null(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        // Ticket type present, but no start date.
        $event = Event::factory()->for($company)->unpublished()->create([
            'starts_at' => null,
        ]);
        TicketType::factory()->forEvent($event)->create();

        $response = $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/publish");

        $response->assertRedirect(route('dashboard.events.show', $event));
        // Requirement 1.2 — the Event stays unpublished.
        $this->assertFalse($event->fresh()->isPublished());
        // The refusal lists the start-date blocker.
        $response->assertSessionHas('publish_errors', function (array $errors): bool {
            return in_array('Set a start date and time.', $errors, true);
        });
    }

    public function test_publish_refusal_lists_both_blockers_when_both_are_missing(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        // Neither prerequisite met: no start date, no ticket type.
        $event = Event::factory()->for($company)->unpublished()->create([
            'starts_at' => null,
        ]);

        $response = $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/publish");

        $response->assertRedirect(route('dashboard.events.show', $event));
        $this->assertFalse($event->fresh()->isPublished());
        // Requirement 1.2 — both blockers are surfaced.
        $response->assertSessionHas('publish_errors', function (array $errors): bool {
            return in_array('Set a start date and time.', $errors, true)
                && in_array('Add at least one ticket type.', $errors, true);
        });
    }

    // ---- Unpublish is unconditional (Requirement 1.3) -----------------------

    public function test_unpublish_always_works_regardless_of_blockers(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->for($company)->create();

        // A published Event that has unmet blockers (no ticket type, no start
        // date) can still be unpublished.
        $event = Event::factory()->for($company)->published()->create([
            'starts_at' => null,
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/unpublish")
            ->assertRedirect(route('dashboard.events.show', $event));

        // Requirement 1.3 — unpublish succeeds regardless of publish blockers.
        $this->assertFalse($event->fresh()->isPublished());
    }

    // ---- Role gating (Requirement 1.5) --------------------------------------

    public function test_non_admin_roles_cannot_publish_or_unpublish(): void
    {
        $company = Company::factory()->create();

        $published = Event::factory()->for($company)->published()->create();
        $unpublished = Event::factory()->for($company)->unpublished()->create();
        TicketType::factory()->forEvent($unpublished)->create();

        foreach ([
            User::factory()->accountant()->for($company)->create(),
            User::factory()->scanner()->for($company)->create(),
        ] as $user) {
            // Requirement 1.5 — publishing is denied with 403.
            $this->actingAs($user)
                ->post("/dashboard/events/{$unpublished->id}/publish")
                ->assertForbidden();

            // Requirement 1.5 — unpublishing is denied with 403.
            $this->actingAs($user)
                ->post("/dashboard/events/{$published->id}/unpublish")
                ->assertForbidden();
        }

        // Published states are unchanged.
        $this->assertFalse($unpublished->fresh()->isPublished());
        $this->assertTrue($published->fresh()->isPublished());
    }

    // ---- Tenant isolation (Requirement 1.6) ---------------------------------

    public function test_publishing_a_foreign_companys_event_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        // A ready-to-publish Event that belongs to a different Company.
        $foreignEvent = Event::factory()->unpublished()->create();
        TicketType::factory()->forEvent($foreignEvent)->create();

        // Requirement 1.6 — foreign Event is not found under the tenant scope.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$foreignEvent->id}/publish")
            ->assertNotFound();

        $this->assertFalse($foreignEvent->fresh()->isPublished());
    }

    public function test_unpublishing_a_foreign_companys_event_returns_404(): void
    {
        $admin = User::factory()->admin()->create();

        $foreignEvent = Event::factory()->published()->create(); // different Company

        // Requirement 1.6 — foreign Event is not found under the tenant scope.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$foreignEvent->id}/unpublish")
            ->assertNotFound();

        // Untouched.
        $this->assertTrue($foreignEvent->fresh()->isPublished());
    }
}
