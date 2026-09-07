<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventSponsor;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: event sponsors management.
 *
 * Sponsors are a repeatable per-Event list managed on the dedicated Sponsors
 * screen (Admin-gated). Every sponsor shows on the public page; up to three may
 * be flagged to print on the ticket. Sponsors can be reordered and bulk-copied
 * from another of the Company's events.
 *
 * Assertions read sponsors via {@see EventSponsor::withoutGlobalScopes()}
 * because no tenant is resolved in the test process outside the HTTP request,
 * so the tenant scope would otherwise hide every row.
 */
class EventSponsorManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Company, 2: Event}
     */
    private function adminEvent(): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        return [$admin, $company, $event];
    }

    /**
     * The Event's sponsors, read without the tenant scope, in display order.
     *
     * @return Collection<int, EventSponsor>
     */
    private function sponsorsOf(Event $event)
    {
        return EventSponsor::withoutGlobalScopes()
            ->where('event_id', $event->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function test_admin_adds_a_sponsor_with_details(): void
    {
        Storage::fake('public');
        [$admin, , $event] = $this->adminEvent();

        $this->actingAs($admin)->post(route('dashboard.events.sponsors.store', $event), [
            'image' => UploadedFile::fake()->image('sponsor.png', 600, 200),
            'name' => 'Acme Corp',
            'website_url' => 'https://acme.example.com',
            'bio' => 'Proud sponsor.',
            'on_ticket' => '1',
        ])->assertRedirect(route('dashboard.events.sponsors', $event));

        $sponsor = $this->sponsorsOf($event)->first();
        $this->assertNotNull($sponsor);
        $this->assertSame('Acme Corp', $sponsor->name);
        $this->assertSame('https://acme.example.com', $sponsor->website_url);
        $this->assertSame('Proud sponsor.', $sponsor->bio);
        $this->assertTrue($sponsor->on_ticket);
        Storage::disk('public')->assertExists($sponsor->image_path);
    }

    public function test_adding_a_sponsor_requires_an_image(): void
    {
        Storage::fake('public');
        [$admin, , $event] = $this->adminEvent();

        $this->actingAs($admin)
            ->post(route('dashboard.events.sponsors.store', $event), ['name' => 'No Logo'])
            ->assertSessionHasErrors('image');

        $this->assertCount(0, $this->sponsorsOf($event));
    }

    public function test_on_ticket_sponsors_are_capped_at_three(): void
    {
        Storage::fake('public');
        [$admin, , $event] = $this->adminEvent();

        EventSponsor::factory()->forEvent($event)->onTicket()->count(3)->create();

        // A fourth on-ticket sponsor is rejected; the store-page list is fine.
        $this->actingAs($admin)->post(route('dashboard.events.sponsors.store', $event), [
            'image' => UploadedFile::fake()->image('fourth.png'),
            'on_ticket' => '1',
        ])->assertSessionHasErrors('on_ticket');

        $this->assertCount(3, $this->sponsorsOf($event)->where('on_ticket', true));
    }

    public function test_admin_reorders_sponsors(): void
    {
        Storage::fake('public');
        [$admin, , $event] = $this->adminEvent();

        $first = EventSponsor::factory()->forEvent($event)->create(['sort_order' => 0]);
        $second = EventSponsor::factory()->forEvent($event)->create(['sort_order' => 1]);

        $this->actingAs($admin)->post(route('dashboard.events.sponsors.reorder', $event), [
            'order' => [$second->id, $first->id],
        ])->assertRedirect(route('dashboard.events.sponsors', $event));

        $this->assertSame(0, (int) EventSponsor::withoutGlobalScopes()->find($second->id)->sort_order);
        $this->assertSame(1, (int) EventSponsor::withoutGlobalScopes()->find($first->id)->sort_order);
    }

    public function test_admin_removes_a_sponsor_and_its_file(): void
    {
        Storage::fake('public');
        [$admin, , $event] = $this->adminEvent();

        Storage::disk('public')->put('branding/sponsors/x.png', 'bytes');
        $sponsor = EventSponsor::factory()->forEvent($event)->create(['image_path' => 'branding/sponsors/x.png']);

        $this->actingAs($admin)
            ->delete(route('dashboard.events.sponsors.destroy', [$event, $sponsor]))
            ->assertRedirect(route('dashboard.events.sponsors', $event));

        $this->assertNull(EventSponsor::withoutGlobalScopes()->find($sponsor->id));
        Storage::disk('public')->assertMissing('branding/sponsors/x.png');
    }

    public function test_admin_copies_sponsors_from_another_event(): void
    {
        Storage::fake('public');
        [$admin, $company, $target] = $this->adminEvent();

        $source = Event::factory()->for($company)->create();
        Storage::disk('public')->put('branding/sponsors/s1.png', 'one');
        Storage::disk('public')->put('branding/sponsors/s2.png', 'two');
        EventSponsor::factory()->forEvent($source)->onTicket()->create(['image_path' => 'branding/sponsors/s1.png', 'name' => 'One']);
        EventSponsor::factory()->forEvent($source)->create(['image_path' => 'branding/sponsors/s2.png', 'name' => 'Two']);

        $this->actingAs($admin)->post(route('dashboard.events.sponsors.copy', $target), [
            'source_event_id' => $source->id,
        ])->assertRedirect(route('dashboard.events.sponsors', $target));

        $copied = $this->sponsorsOf($target);
        $this->assertCount(2, $copied);
        // Names carried over; copies start off the ticket regardless of source.
        $this->assertEqualsCanonicalizing(['One', 'Two'], $copied->pluck('name')->all());
        $this->assertTrue($copied->every(fn ($s) => $s->on_ticket === false));
        // Image files were duplicated to new paths, not shared with the source.
        foreach ($copied as $sponsor) {
            Storage::disk('public')->assertExists($sponsor->image_path);
            $this->assertNotContains($sponsor->image_path, ['branding/sponsors/s1.png', 'branding/sponsors/s2.png']);
        }
    }

    public function test_sponsors_of_another_company_are_not_reachable(): void
    {
        Storage::fake('public');
        [$admin, , $event] = $this->adminEvent();

        // A sponsor on a different Company's event: the nested route 404s.
        $foreignEvent = Event::factory()->create();
        $foreignSponsor = EventSponsor::factory()->forEvent($foreignEvent)->create();

        $this->actingAs($admin)
            ->delete(route('dashboard.events.sponsors.destroy', [$event, $foreignSponsor]))
            ->assertNotFound();
    }
}
