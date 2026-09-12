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

    /**
     * The legal/registration + address fields the branding form always submits
     * and the controller requires (captured at signup, maintained here). Tests
     * that exercise a branding facet merge these so the required subset is
     * present, mirroring the real form which carries every field on each save.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function orgFields(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Acme Events Ltd',
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'email' => 'hello@acme.test',
            'address_line_1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'GB',
        ], $overrides);
    }

    // ---- Company-level branding (Owner) --------------------------------------

    public function test_owner_uploads_logo_which_is_stored_and_path_persisted(): void
    {
        // Requirement 7.1 — an uploaded logo is stored and its path persisted.
        Storage::fake('public');
        $owner = User::factory()->owner()->create();

        $response = $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
        ]));

        $response->assertRedirect(route('dashboard.branding.edit'));

        $logoPath = $owner->company->fresh()->logo_path;
        $this->assertNotNull($logoPath);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_owner_sets_primary_colour_and_terms(): void
    {
        // Requirements 7.2, 7.3.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'primary_colour' => '#ABCDEF',
            'terms_text' => 'You agree to the terms.',
        ]))->assertRedirect();

        $company = $owner->company->fresh();
        $this->assertSame('#ABCDEF', $company->primary_colour);
        $this->assertSame('You agree to the terms.', $company->terms_text);
    }

    public function test_uploading_a_new_logo_removes_the_previous_one(): void
    {
        Storage::fake('public');
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'logo' => UploadedFile::fake()->image('first.png'),
        ]));
        $first = $owner->company->fresh()->logo_path;

        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'logo' => UploadedFile::fake()->image('second.png'),
        ]));
        $second = $owner->company->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);
    }

    public function test_invalid_primary_colour_is_rejected(): void
    {
        // Requirement 7.2 — colour must be a valid hex value.
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'primary_colour' => 'not-a-colour',
        ]))->assertSessionHasErrors('primary_colour');

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
        ])->assertRedirect(route('dashboard.branding.event.edit', $event));

        $fresh = $event->fresh();
        $this->assertSame('#999999', $fresh->primary_colour);
    }

    public function test_admin_sets_event_ticket_design_instructions_and_sponsors(): void
    {
        // Per-event ticket design: the branding form owns the custom ticket
        // instructions. (Sponsors are managed on their own screen — see
        // EventSponsorManagementTest.)
        Storage::fake('public');

        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $event = Event::factory()->for($company)->create();

        $this->actingAs($admin)->put(route('dashboard.branding.event.update', $event), [
            'ticket_instructions' => 'Bring photo ID. Doors open 30 minutes early.',
        ])->assertRedirect(route('dashboard.branding.event.edit', $event));

        $this->assertSame(
            'Bring photo ID. Doors open 30 minutes early.',
            $event->fresh()->ticket_instructions,
        );
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

    // ---- Impersonation (Super_Admin jumped into a tenant) --------------------

    public function test_impersonating_super_admin_sees_the_impersonated_companys_branding(): void
    {
        // Regression: while impersonating, the branding screen must show the
        // impersonated Company's settings — resolved from the bound tenant —
        // not the Super_Admin's own (they have none).
        Storage::fake('public');
        $superAdmin = User::factory()->superAdmin()->create();
        $company = Company::factory()->create([
            'primary_colour' => '#ABCDEF',
            'logo_path' => 'branding/logos/tenant.png',
        ]);

        $response = $this->actingAs($superAdmin)
            ->withSession([\App\Http\Controllers\SuperAdmin\ImpersonationController::SESSION_KEY => $company->id])
            ->get(route('dashboard.branding.edit'));

        $response->assertOk();
        $response->assertSee('#ABCDEF');
        $response->assertSee('tenant.png');
    }

    public function test_impersonating_super_admin_edits_the_impersonated_company_not_their_own(): void
    {
        // Regression: a saved branding change must land on the impersonated
        // Company, not the acting Super_Admin's account.
        $superAdmin = User::factory()->superAdmin()->create();
        $company = Company::factory()->create(['primary_colour' => null]);

        $this->actingAs($superAdmin)
            ->withSession([\App\Http\Controllers\SuperAdmin\ImpersonationController::SESSION_KEY => $company->id])
            ->put(route('dashboard.branding.update'), $this->orgFields([
                'primary_colour' => '#123456',
                'terms_text' => 'Tenant terms.',
            ]))
            ->assertRedirect(route('dashboard.branding.edit'));

        $fresh = $company->fresh();
        $this->assertSame('#123456', $fresh->primary_colour);
        $this->assertSame('Tenant terms.', $fresh->terms_text);
    }

    // ---- BrandingResolver ----------------------------------------------------

    public function test_resolver_returns_company_branding_for_storefront(): void
    {
        // Requirements 7.1–7.3 — Company-level branding as-is.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
            'terms_text' => 'Company terms.',
        ]);

        $branding = app(BrandingResolver::class)->forCompany($company);

        $this->assertSame('branding/logos/co.png', $branding->logoPath);
        $this->assertSame('#010203', $branding->primaryColour);
        $this->assertSame('Company terms.', $branding->termsText);
    }

    public function test_resolver_prefers_event_overrides_over_company(): void
    {
        // Requirement 7.5 — Event-level values win where set.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
            'terms_text' => 'Company terms.',
        ]);
        $event = Event::factory()->for($company)->create([
            'logo_path' => 'branding/logos/event.png',
            'primary_colour' => '#0A0B0C',
        ]);

        $branding = app(BrandingResolver::class)->forEvent($event);

        $this->assertSame('branding/logos/event.png', $branding->logoPath);
        $this->assertSame('#0A0B0C', $branding->primaryColour);
        // Terms are a Company checkout setting; Events do not override. (7.3)
        $this->assertSame('Company terms.', $branding->termsText);
    }

    public function test_resolver_falls_back_to_company_where_event_unset(): void
    {
        // Requirement 7.5 — each facet falls back independently.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
        ]);
        $event = Event::factory()->for($company)->create([
            'logo_path' => null,
            'primary_colour' => '#0A0B0C',
        ]);

        $branding = app(BrandingResolver::class)->forEvent($event);

        // Logo inherited; only colour overridden.
        $this->assertSame('branding/logos/co.png', $branding->logoPath);
        $this->assertSame('#0A0B0C', $branding->primaryColour);
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
