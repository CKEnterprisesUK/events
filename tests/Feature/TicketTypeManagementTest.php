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
 * Covers task 6.2 — the TicketTypeController (create/update) scoped to the
 * Company and Event, with field and sale-window validation.
 *
 * Requirements: 6.1 (name 1–100, price 0.00–999,999.99 stored as integer minor
 * units, capacity 1–1,000,000; accepted inputs round-trip), 6.2 (1–50
 * Ticket_Types per Event), 6.3 (price 0 = free), 6.9 (sale-window end strictly
 * after start). Cross-Company isolation (1.5) and Admin gating (3.4, 3.7) are
 * exercised alongside.
 */
class TicketTypeManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an Admin and an Event belonging to that Admin's Company.
     *
     * @return array{0: User, 1: Event}
     */
    private function adminWithEvent(): array
    {
        $admin = User::factory()->admin()->create();
        $event = Event::factory()->for(Company::find($admin->company_id))->create();

        return [$admin, $event];
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'General Admission',
            'price' => '12.50',
            'capacity' => 100,
            'sale_starts_at' => now()->addDay()->toDateTimeString(),
            'sale_ends_at' => now()->addWeek()->toDateTimeString(),
        ], $overrides);
    }

    // ---- Create / round-trip -------------------------------------------------

    public function test_admin_creates_a_ticket_type_and_price_maps_to_minor_units(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Requirement 6.1 — "12.50" maps to price_minor 1250 and round-trips.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload())
            ->assertRedirect(route('dashboard.events.ticket-types.index', $event));

        $this->assertDatabaseHas('ticket_types', [
            'company_id' => $admin->company_id,
            'event_id' => $event->id,
            'name' => 'General Admission',
            'price_minor' => 1250,
            'capacity' => 100,
        ]);

        $ticketType = TicketType::withoutGlobalScopes()->where('event_id', $event->id)->first();
        $this->assertSame(1250, $ticketType->price_minor);
        $this->assertSame(12.50, round($ticketType->price_minor / 100, 2));
    }

    public function test_price_zero_is_stored_as_free(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Requirement 6.3 — price 0 => free.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'price' => '0',
            ]))->assertRedirect();

        $ticketType = TicketType::withoutGlobalScopes()->where('event_id', $event->id)->first();
        $this->assertSame(0, $ticketType->price_minor);
        $this->assertTrue($ticketType->isFree());
    }

    public function test_maximum_price_is_accepted_and_round_trips(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Requirement 6.1 — 999,999.99 is the upper bound => 99,999,999 minor.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'price' => '999999.99',
            ]))->assertRedirect();

        $this->assertDatabaseHas('ticket_types', [
            'event_id' => $event->id,
            'price_minor' => 99_999_999,
        ]);
    }

    public function test_admin_updates_a_ticket_type_and_details_persist(): void
    {
        [$admin, $event] = $this->adminWithEvent();
        $ticketType = TicketType::factory()->forEvent($event)->create([
            'name' => 'Old',
            'price_minor' => 500,
        ]);

        // Requirement 6.1 — updated fields persist.
        $this->actingAs($admin)
            ->put("/dashboard/events/{$event->id}/ticket-types/{$ticketType->id}", $this->validPayload([
                'name' => 'VIP',
                'price' => '45.00',
                'capacity' => 20,
            ]))->assertRedirect(route('dashboard.events.ticket-types.index', $event));

        $this->assertDatabaseHas('ticket_types', [
            'id' => $ticketType->id,
            'name' => 'VIP',
            'price_minor' => 4500,
            'capacity' => 20,
        ]);
    }

    // ---- Remaining-availability display -------------------------------------

    public function test_index_surfaces_remaining_availability(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Remaining available = capacity - sold_count - reserved_count.
        // (Requirement 6.10) => 100 - 30 - 20 = 50.
        $ticketType = TicketType::factory()->forEvent($event)->create([
            'name' => 'General Admission',
            'capacity' => 100,
            'sold_count' => 30,
            'reserved_count' => 20,
        ]);

        $this->assertSame(50, $ticketType->availableQuantity());

        $this->actingAs($admin)
            ->get(route('dashboard.events.ticket-types.index', $event))
            ->assertStatus(200)
            ->assertSee('50 remaining');
    }

    // ---- Field validation ----------------------------------------------------

    public function test_name_must_be_1_to_100_characters(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Empty name rejected.
        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'name' => '',
            ]))->assertSessionHasErrors('name');

        // 101 chars rejected.
        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'name' => str_repeat('a', 101),
            ]))->assertSessionHasErrors('name');

        $this->assertDatabaseCount('ticket_types', 0);

        // Exactly 100 chars accepted.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'name' => str_repeat('a', 100),
            ]))->assertRedirect();

        $this->assertDatabaseCount('ticket_types', 1);
    }

    public function test_price_above_maximum_is_rejected(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Requirement 6.1 — price above 999,999.99 rejected.
        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'price' => '1000000.00',
            ]))->assertSessionHasErrors('price');

        // Negative price rejected.
        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'price' => '-1',
            ]))->assertSessionHasErrors('price');

        $this->assertDatabaseCount('ticket_types', 0);
    }

    public function test_capacity_must_be_between_1_and_1000000(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        foreach (['0', '1000001'] as $bad) {
            $this->actingAs($admin)->from('/dashboard')
                ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                    'capacity' => $bad,
                ]))->assertSessionHasErrors('capacity');
        }

        $this->assertDatabaseCount('ticket_types', 0);

        // Upper bound accepted.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'capacity' => 1_000_000,
            ]))->assertRedirect();

        $this->assertDatabaseHas('ticket_types', [
            'event_id' => $event->id,
            'capacity' => 1_000_000,
        ]);
    }

    // ---- Sale-window validation ---------------------------------------------

    public function test_sale_window_end_must_be_strictly_after_start(): void
    {
        [$admin, $event] = $this->adminWithEvent();
        $moment = now()->addDay()->toDateTimeString();

        // Requirement 6.9 — equal start/end rejected (not strictly after).
        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'sale_starts_at' => $moment,
                'sale_ends_at' => $moment,
            ]))->assertSessionHasErrors('sale_ends_at');

        // End before start rejected.
        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload([
                'sale_starts_at' => now()->addWeek()->toDateTimeString(),
                'sale_ends_at' => now()->addDay()->toDateTimeString(),
            ]))->assertSessionHasErrors('sale_ends_at');

        $this->assertDatabaseCount('ticket_types', 0);
    }

    // ---- 1–50 types per Event ------------------------------------------------

    public function test_event_cannot_exceed_50_ticket_types(): void
    {
        [$admin, $event] = $this->adminWithEvent();

        // Requirement 6.2 — seed the Event at the 50-type ceiling.
        TicketType::factory()->forEvent($event)->count(50)->create();

        $this->actingAs($admin)->from('/dashboard')
            ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload())
            ->assertSessionHasErrors('name');

        $this->assertSame(50, TicketType::withoutGlobalScopes()->where('event_id', $event->id)->count());
    }

    // ---- Tenant isolation ----------------------------------------------------

    public function test_admin_cannot_manage_another_companys_event_ticket_types(): void
    {
        [$admin] = $this->adminWithEvent();
        $otherEvent = Event::factory()->create(); // different Company

        // Requirement 1.5 — cross-Company Event is not found under the scope.
        $this->actingAs($admin)
            ->post("/dashboard/events/{$otherEvent->id}/ticket-types", $this->validPayload())
            ->assertNotFound();

        $this->assertDatabaseCount('ticket_types', 0);
    }

    public function test_ticket_type_of_another_event_is_not_reachable_via_this_event(): void
    {
        [$admin, $event] = $this->adminWithEvent();
        // A second Event of the same Company with its own Ticket_Type.
        $otherEvent = Event::factory()->for(Company::find($admin->company_id))->create();
        $foreign = TicketType::factory()->forEvent($otherEvent)->create();

        // The nested route must not update a Ticket_Type of a different Event.
        $this->actingAs($admin)
            ->put("/dashboard/events/{$event->id}/ticket-types/{$foreign->id}", $this->validPayload())
            ->assertNotFound();
    }

    // ---- Role gating ---------------------------------------------------------

    public function test_non_admin_roles_cannot_manage_ticket_types(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->create();

        foreach ([
            User::factory()->for($company)->accountant()->create(),
            User::factory()->for($company)->scanner()->create(),
        ] as $user) {
            // Requirements 3.4, 3.7 — only Admin manages ticket types.
            $this->actingAs($user)
                ->post("/dashboard/events/{$event->id}/ticket-types", $this->validPayload())
                ->assertForbidden();
        }

        $this->assertDatabaseCount('ticket_types', 0);
    }
}
