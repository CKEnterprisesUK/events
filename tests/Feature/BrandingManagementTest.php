<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\User;
use App\Services\Branding\BrandingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 11.1 — BrandingController and the BrandingResolver.
 *
 * Requirements: 7.1 (upload/store/display logo), 7.2 (primary brand colour),
 * 7.3 (Terms & Conditions text shown at checkout), 7.4 (custom ticket info
 * fields printed on tickets), 7.5 (Event-level overrides fall back to Company).
 * Company-level branding is Owner-gated (ACTION_MANAGE_SETTINGS); Event-level
 * overrides are Admin-gated (ACTION_MANAGE_EVENTS). Cross-Company isolation
 * (1.5) is exercised alongside.
 */
class BrandingManagementTest extends TestCase
{
    use RefreshDatabase;

    // ---- Company-level branding (Owner) --------------------------------------

    public function test_owner_uploads_logo_which_is_stored_and_path_persisted(): void
    {
        // Requirement 7.1 — an uploaded logo is stored and its path persisted.
        Storage::fake('public');
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->put(route('dashboard.branding.update'), [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]);

        $response->assertRedirect(route('dashboard.branding.edit'));

        $logoPath = $owner->company->fresh()->logo_path;
        $this->assertNotNull($logoPath);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_owner_sets_primary_colour_terms_and_custom_fields(): void
    {
        // Requirements 7.2, 7.3, 7.4.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), [
            'primary_colour' => '#ABCDEF',
            'terms_text' => 'You agree to the terms.',
            'ticket_field_defs' => ['Seat', 'Table', ''],
        ])->assertRedirect();

        $company = $owner->company->fresh();
        $this->assertSame('#ABCDEF', $company->primary_colour);
        $this->assertSame('You agree to the terms.', $company->terms_text);
        // Blank field entries are dropped; the rest stored as label defs. (7.4)
        $this->assertSame(
            [['label' => 'Seat'], ['label' => 'Table']],
            $company->ticket_field_defs,
        );
    }

    public function test_uploading_a_new_logo_removes_the_previous_one(): void
    {
        Storage::fake('public');
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), [
            'logo' => UploadedFile::fake()->image('first.png'),
        ]);
        $first = $owner->company->fresh()->logo_path;

        $this->actingAs($owner)->put(route('dashboard.branding.update'), [
            'logo' => UploadedFile::fake()->image('second.png'),
        ]);
        $second = $owner->company->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_invalid_primary_colour_is_rejected(): void
    {
        // Requirement 7.2 — colour must be a valid hex value.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), [
            'primary_colour' => 'not-a-colour',
        ])->assertSessionHasErrors('primary_colour');

        $this->assertNull($owner->company->fresh()->primary_colour);
    }

    public function test_company_branding_edit_renders_logo_and_colour(): void
    {
        Storage::fake('public');
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'primary_colour' => '#123456',
            'logo_path' => 'branding/logos/existing.png',
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.branding.edit'));

        $response->assertOk();
        $response->assertSee('#123456');
        $response->assertSee('existing.png');
    }

    public function test_non_owner_cannot_manage_company_branding_and_nothing_changes(): void
    {
        // ACTION_MANAGE_SETTINGS is Owner-only: an Admin is denied (403).
        $company = Company::factory()->create(['primary_colour' => null]);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);

        $this->actingAs($admin)->get(route('dashboard.branding.edit'))->assertForbidden();
        $this->actingAs($admin)->put(route('dashboard.branding.update'), [
            'primary_colour' => '#000000',
        ])->assertForbidden();

        $this->assertNull($company->fresh()->primary_colour);
    }

    // ---- Event-level branding overrides (Admin) ------------------------------

    public function test_admin_sets_event_branding_override(): void
    {
        // Requirement 7.5 — per-Event overrides.
        $company = Company::factory()->create(['primary_colour' => '#111111']);
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create(['primary_colour' => null]);

        $this->actingAs($admin)->put(route('dashboard.branding.event.update', $event), [
            'primary_colour' => '#999999',
            'ticket_field_defs' => ['VIP note'],
        ])->assertRedirect(route('dashboard.branding.event.edit', $event));

        $fresh = $event->fresh();
        $this->assertSame('#999999', $fresh->primary_colour);
        $this->assertSame([['label' => 'VIP note']], $fresh->ticket_field_defs);
    }

    public function test_admin_cannot_override_branding_for_another_companys_event(): void
    {
        // Requirement 1.5 — cross-Company Event id surfaces as 404.
        $adminCompany = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $adminCompany->id]);

        $otherEvent = Event::factory()->create(['primary_colour' => '#000000']);

        $this->actingAs($admin)->put(route('dashboard.branding.event.update', $otherEvent), [
            'primary_colour' => '#FFFFFF',
        ])->assertNotFound();

        $this->assertSame('#000000', $otherEvent->fresh()->primary_colour);
    }

    public function test_accountant_cannot_override_event_branding(): void
    {
        // ACTION_MANAGE_EVENTS is not granted to Accountant.
        $company = Company::factory()->create();
        $accountant = User::factory()->accountant()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($accountant)
            ->get(route('dashboard.branding.event.edit', $event))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('dashboard.branding.edit'))->assertRedirect(route('login'));
    }

    // ---- BrandingResolver ----------------------------------------------------

    public function test_resolver_returns_company_branding_for_storefront(): void
    {
        // Requirements 7.1–7.4 — Company-level branding as-is.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
            'terms_text' => 'Company terms.',
            'ticket_field_defs' => [['label' => 'Seat']],
        ]);

        $branding = app(BrandingResolver::class)->forCompany($company);

        $this->assertSame('branding/logos/co.png', $branding->logoPath);
        $this->assertSame('#010203', $branding->primaryColour);
        $this->assertSame('Company terms.', $branding->termsText);
        $this->assertSame([['label' => 'Seat']], $branding->ticketFieldDefs);
    }

    public function test_resolver_prefers_event_overrides_over_company(): void
    {
        // Requirement 7.5 — Event-level values win where set.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
            'terms_text' => 'Company terms.',
            'ticket_field_defs' => [['label' => 'Seat']],
        ]);
        $event = Event::factory()->for($company)->create([
            'logo_path' => 'branding/logos/event.png',
            'primary_colour' => '#0A0B0C',
            'ticket_field_defs' => [['label' => 'Table']],
        ]);

        $branding = app(BrandingResolver::class)->forEvent($event);

        $this->assertSame('branding/logos/event.png', $branding->logoPath);
        $this->assertSame('#0A0B0C', $branding->primaryColour);
        // Terms are a Company checkout setting; Events do not override. (7.3)
        $this->assertSame('Company terms.', $branding->termsText);
        $this->assertSame([['label' => 'Table']], $branding->ticketFieldDefs);
    }

    public function test_resolver_falls_back_to_company_where_event_unset(): void
    {
        // Requirement 7.5 — each facet falls back independently.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
            'ticket_field_defs' => [['label' => 'Seat']],
        ]);
        $event = Event::factory()->for($company)->create([
            'logo_path' => null,
            'primary_colour' => '#0A0B0C',
            'ticket_field_defs' => null,
        ]);

        $branding = app(BrandingResolver::class)->forEvent($event);

        // Logo and fields inherited; only colour overridden.
        $this->assertSame('branding/logos/co.png', $branding->logoPath);
        $this->assertSame('#0A0B0C', $branding->primaryColour);
        $this->assertSame([['label' => 'Seat']], $branding->ticketFieldDefs);
    }

    public function test_resolver_treats_blank_event_override_as_unset(): void
    {
        // A blank override should fall through to the Company value, not blank
        // the surface. (Requirement 7.5)
        $company = Company::factory()->create(['primary_colour' => '#010203']);
        $event = Event::factory()->for($company)->create(['primary_colour' => '   ']);

        $branding = app(BrandingResolver::class)->forEvent($event);

        $this->assertSame('#010203', $branding->primaryColour);
    }
}
