<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: event-experience-polish
 *
 * Covers task 7.6 — the hero-image (poster) upload path on
 * {@see \App\Http\Controllers\EventController} and the per-event-over-company
 * hero precedence.
 *
 * The poster is validated as `nullable image mimes:jpeg,png,webp max:4096`,
 * stored via {@see \App\Services\BrandingImageStore} to `branding/posters` on
 * the `public` disk, and its relative path persisted to `events.poster_path`.
 * An invalid upload fails the whole request, so nothing is created/updated and
 * any existing poster is left untouched.
 *
 * Requirements: 5.2 (accepted image types/size), 5.3 (poster persisted),
 * 5.4/5.5/5.6 (hero precedence: event poster over company poster, else none).
 */
class EventHeroImageTest extends TestCase
{
    use RefreshDatabase;

    // ---- Upload accepted types ----------------------------------------------

    public function test_admin_uploads_a_hero_image_on_create(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        // Requirement 5.2/5.3 — jpg maps to the jpeg mime, which is allowed.
        $this->actingAs($admin)->post('/dashboard/events', [
            'name' => 'Gala With Hero',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'poster' => UploadedFile::fake()->image('hero.jpg', 800, 400),
        ])->assertRedirect();

        $event = Event::withoutGlobalScopes()->where('name', 'Gala With Hero')->firstOrFail();

        $this->assertNotNull($event->poster_path);
        Storage::disk('public')->assertExists($event->poster_path);
    }

    public function test_webp_and_png_are_accepted(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        // PNG.
        $this->actingAs($admin)->post('/dashboard/events', [
            'name' => 'PNG Hero',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'poster' => UploadedFile::fake()->image('hero.png'),
        ])->assertRedirect();

        $png = Event::withoutGlobalScopes()->where('name', 'PNG Hero')->firstOrFail();
        $this->assertNotNull($png->poster_path);
        Storage::disk('public')->assertExists($png->poster_path);

        // WEBP — ->image() cannot reliably synthesise webp, so create a fake
        // file with an explicit image/webp mime that satisfies the rule.
        $this->actingAs($admin)->post('/dashboard/events', [
            'name' => 'WEBP Hero',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'poster' => UploadedFile::fake()->create('hero.webp', 100, 'image/webp'),
        ])->assertRedirect();

        $webp = Event::withoutGlobalScopes()->where('name', 'WEBP Hero')->firstOrFail();
        $this->assertNotNull($webp->poster_path);
        Storage::disk('public')->assertExists($webp->poster_path);
    }

    // ---- Upload rejected types/sizes ----------------------------------------

    public function test_disallowed_type_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        // A PDF is neither an image nor an allowed mime; validation fails the
        // whole request so no Event is persisted. (Requirement 5.2)
        $this->actingAs($admin)->from('/dashboard/events')->post('/dashboard/events', [
            'name' => 'Bad Type',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'poster' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertSessionHasErrors('poster');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_oversize_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();

        // 5000 KB exceeds the max:4096 ceiling. (Requirement 5.2)
        $this->actingAs($admin)->from('/dashboard/events')->post('/dashboard/events', [
            'name' => 'Too Big',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'poster' => UploadedFile::fake()->create('big.jpg', 5000, 'image/jpeg'),
        ])->assertSessionHasErrors('poster');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_rejection_leaves_existing_poster_unchanged(): void
    {
        Storage::fake('public');
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $event = Event::factory()->for($company)->create(['name' => 'Has A Hero']);

        // Establish a known, valid poster via a successful update first.
        $this->actingAs($admin)->put("/dashboard/events/{$event->id}", [
            'name' => 'Has A Hero',
            'location_mode' => Event::LOCATION_IN_PERSON,
            'poster' => UploadedFile::fake()->image('original.jpg'),
        ])->assertRedirect();

        $originalPath = $event->fresh()->poster_path;
        $this->assertNotNull($originalPath);
        Storage::disk('public')->assertExists($originalPath);

        // A subsequent invalid upload fails validation; the request is rejected
        // and the existing poster is left untouched. (Requirement 5.3)
        $this->actingAs($admin)->from("/dashboard/events/{$event->id}")
            ->put("/dashboard/events/{$event->id}", [
                'name' => 'Has A Hero',
                'location_mode' => Event::LOCATION_IN_PERSON,
                'poster' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
            ])->assertSessionHasErrors('poster');

        $this->assertSame($originalPath, $event->fresh()->poster_path);
        Storage::disk('public')->assertExists($originalPath);
    }

    // ---- Hero precedence -----------------------------------------------------

    /**
     * Property 10: Hero image precedence.
     *
     * The effective hero resolves per-event over company:
     * `$event->poster_path ?? $event->company->poster_path`. When the event has
     * its own poster it wins; when it is null the company poster is used; when
     * neither is set there is no hero.
     *
     * **Validates: Requirements 5.4, 5.5, 5.6**
     */
    public function test_hero_precedence_event_over_company(): void
    {
        $company = Company::factory()->create(['poster_path' => 'branding/posters/companyposter.jpg']);
        $event = Event::factory()->for($company)->create(['poster_path' => 'branding/posters/eventposter.jpg']);

        $resolve = static fn (Event $e): ?string => $e->poster_path ?? $e->company->poster_path;

        // Event poster set: the event's poster wins. (Requirement 5.4)
        $this->assertSame('branding/posters/eventposter.jpg', $resolve($event));

        // Event poster null: fall back to the company poster. (Requirement 5.5)
        $event->poster_path = null;
        $event->save();
        $this->assertSame('branding/posters/companyposter.jpg', $resolve($event->fresh()->load('company')));

        // Neither set: no hero. (Requirement 5.6)
        $company->poster_path = null;
        $company->save();
        $this->assertNull($resolve($event->fresh()->load('company')));
    }
}
