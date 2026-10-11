<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\EventSponsor;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: Copy event.
 *
 * An Admin can duplicate an Event into a fresh, unpublished draft belonging to
 * the same Company. The copy carries the details, ticket types, questions and
 * sponsors (with duplicated image files) but NONE of the source's booking state
 * (orders, tickets, check-ins), so it is safe to run against a live event and
 * always starts with availability full. Cross-Company sources 404 and only
 * Admins may duplicate.
 */
class EventDuplicateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_duplicates_an_event_into_a_fresh_unpublished_draft(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $source = Event::factory()->for($company)->published()->create([
            'name' => 'Summer Gala',
            'capacity' => 250,
            'starts_at' => now()->addMonth(),
        ]);

        $this->actingAs($admin)
            ->post("/dashboard/events/{$source->id}/duplicate")
            ->assertRedirect();

        $copy = Event::withoutGlobalScopes()
            ->where('id', '!=', $source->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($copy);
        $this->assertSame($company->id, $copy->company_id);
        $this->assertSame('Summer Gala (copy)', $copy->name);
        $this->assertSame(250, $copy->capacity);
        // A copy is always a draft with no inherited date, and never cancelled.
        $this->assertFalse($copy->isPublished());
        $this->assertNull($copy->starts_at);
        $this->assertNull($copy->cancelled_at);
    }

    public function test_ticket_types_are_copied_with_counts_reset(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $source = Event::factory()->for($company)->create();
        TicketType::factory()->forEvent($source)->create([
            'name' => 'General',
            'price_minor' => 1500,
            'capacity' => 100,
            'sold_count' => 40,
            'reserved_count' => 5,
        ]);

        $this->actingAs($admin)->post("/dashboard/events/{$source->id}/duplicate")->assertRedirect();

        $copy = Event::withoutGlobalScopes()->where('id', '!=', $source->id)->latest('id')->first();
        $type = TicketType::withoutGlobalScopes()->where('event_id', $copy->id)->first();

        $this->assertNotNull($type);
        $this->assertSame('General', $type->name);
        $this->assertSame(1500, $type->price_minor);
        $this->assertSame(100, $type->capacity);
        // The copy has taken no bookings — availability starts full.
        $this->assertSame(0, $type->sold_count);
        $this->assertSame(0, $type->reserved_count);
    }

    public function test_questions_are_copied(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $source = Event::factory()->for($company)->create();
        $source->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_SELECT,
            'label' => 'Meal choice',
            'options' => ['Meat', 'Vegetarian'],
            'required' => true,
            'position' => 0,
        ]);

        $this->actingAs($admin)->post("/dashboard/events/{$source->id}/duplicate")->assertRedirect();

        $copy = Event::withoutGlobalScopes()->where('id', '!=', $source->id)->latest('id')->first();
        $question = EventQuestion::withoutGlobalScopes()->where('event_id', $copy->id)->first();

        $this->assertNotNull($question);
        $this->assertSame('Meal choice', $question->label);
        $this->assertSame(['Meat', 'Vegetarian'], $question->options);
        $this->assertTrue($question->required);
    }

    public function test_sponsors_are_copied_and_their_image_files_duplicated(): void
    {
        Storage::fake('public');

        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $source = Event::factory()->for($company)->create();
        $sourcePath = 'branding/sponsors/original.png';
        Storage::disk('public')->put($sourcePath, 'fake-image-bytes');

        EventSponsor::factory()->forEvent($source)->create([
            'image_path' => $sourcePath,
            'name' => 'Acme Co',
        ]);

        $this->actingAs($admin)->post("/dashboard/events/{$source->id}/duplicate")->assertRedirect();

        $copy = Event::withoutGlobalScopes()->where('id', '!=', $source->id)->latest('id')->first();
        $sponsor = EventSponsor::withoutGlobalScopes()->where('event_id', $copy->id)->first();

        $this->assertNotNull($sponsor);
        $this->assertSame('Acme Co', $sponsor->name);
        // A distinct file on disk, not the source path.
        $this->assertNotSame($sourcePath, $sponsor->image_path);
        Storage::disk('public')->assertExists($sponsor->image_path);
        // The original is untouched.
        Storage::disk('public')->assertExists($sourcePath);
    }

    public function test_bookings_are_not_copied(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $source = Event::factory()->for($company)->create();
        Order::factory()->forEvent($source)->create(['status' => Order::STATUS_PAID]);

        $this->actingAs($admin)->post("/dashboard/events/{$source->id}/duplicate")->assertRedirect();

        $copy = Event::withoutGlobalScopes()->where('id', '!=', $source->id)->latest('id')->first();

        $this->assertSame(0, Order::withoutGlobalScopes()->where('event_id', $copy->id)->count());
        $this->assertFalse($copy->hasBookings());
    }

    public function test_duplicate_is_audited(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $source = Event::factory()->for($company)->create(['name' => 'Gala']);

        $this->actingAs($admin)->post("/dashboard/events/{$source->id}/duplicate")->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'company_id' => $company->id,
            'action' => AuditLog::EVENT_DUPLICATED,
        ]);
    }

    public function test_admin_cannot_duplicate_another_companys_event(): void
    {
        $admin = User::factory()->admin()->create();
        $otherEvent = Event::factory()->create(); // different Company

        $this->actingAs($admin)
            ->post("/dashboard/events/{$otherEvent->id}/duplicate")
            ->assertNotFound();

        // No copy was created for the acting company.
        $this->assertSame(
            0,
            Event::withoutGlobalScopes()->where('company_id', $admin->company_id)->count(),
        );
    }

    public function test_non_admin_roles_cannot_duplicate_events(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->create();

        foreach ([
            User::factory()->accountant()->create(['company_id' => $company->id]),
            User::factory()->scanner()->create(['company_id' => $company->id]),
        ] as $user) {
            $this->actingAs($user)
                ->post("/dashboard/events/{$event->id}/duplicate")
                ->assertForbidden();
        }
    }

    public function test_repeated_duplication_produces_distinct_names(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $source = Event::factory()->for($company)->create(['name' => 'Gala']);

        $this->actingAs($admin)->post("/dashboard/events/{$source->id}/duplicate")->assertRedirect();
        $firstCopy = Event::withoutGlobalScopes()->where('name', 'Gala (copy)')->firstOrFail();

        // Duplicating the copy bumps the numeric suffix rather than repeating.
        $this->actingAs($admin)->post("/dashboard/events/{$firstCopy->id}/duplicate")->assertRedirect();

        $this->assertSame(
            1,
            Event::withoutGlobalScopes()->where('name', 'Gala (copy 2)')->count(),
        );
    }
}
