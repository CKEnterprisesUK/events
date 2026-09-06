<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\EffectiveBranding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 11.2 — branding *resolution + rendering*. Where task 11.1
 * (BrandingManagementTest) exercised the BrandingController write path and the
 * resolver's fallback/override rules, this suite focuses on the resolved
 * EffectiveBranding that actually *feeds the surfaces*: that the logo/colour
 * are render-ready on the Storefront (7.1, 7.2), the Terms & Conditions are
 * surfaced for checkout (7.3), the custom ticket-info fields are available for
 * tickets (7.4), and that an Event-level override reaches the Event surface in
 * preference to the Company default (7.5).
 *
 * The public Storefront / event-page rendering wiring is task 12.1; these tests
 * assert the resolver output and its render-readiness contract (hasLogo,
 * hasPrimaryColour, hasTerms, hasTicketFields) that those surfaces consume, and
 * that an uploaded logo resolves to a stored, displayable asset.
 *
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5.
 */
class BrandingResolutionAndRenderingTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): BrandingResolver
    {
        return app(BrandingResolver::class);
    }

    // ---- 7.1 Logo renders on the Storefront (and resolves to a stored asset) --

    public function test_storefront_branding_exposes_logo_for_rendering(): void
    {
        // Requirement 7.1 — a Company logo is render-ready on the Storefront.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
        ]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertTrue($branding->hasLogo());
        $this->assertSame('branding/logos/co.png', $branding->logoPath);
    }

    public function test_uploaded_logo_resolves_to_a_stored_displayable_asset(): void
    {
        // Requirement 7.1 — the resolved logo path points at a stored asset the
        // Storefront/ticket surfaces can display.
        Storage::fake('public');

        $storedPath = UploadedFile::fake()
            ->image('logo.png', 300, 300)
            ->store('branding/logos', 'public');

        $company = Company::factory()->create(['logo_path' => $storedPath]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertTrue($branding->hasLogo());
        Storage::disk('public')->assertExists($branding->logoPath);
    }

    public function test_storefront_without_logo_is_not_render_ready(): void
    {
        // Requirement 7.1 — no logo set means nothing to display.
        $company = Company::factory()->create(['logo_path' => null]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertFalse($branding->hasLogo());
        $this->assertNull($branding->logoPath);
    }

    // ---- 7.2 Primary colour applies to the Storefront ------------------------

    public function test_storefront_branding_exposes_primary_colour_for_rendering(): void
    {
        // Requirement 7.2 — the primary colour is applied to the Storefront.
        $company = Company::factory()->create(['primary_colour' => '#123456']);

        $branding = $this->resolver()->forCompany($company);

        $this->assertTrue($branding->hasPrimaryColour());
        $this->assertSame('#123456', $branding->primaryColour);
    }

    public function test_storefront_without_primary_colour_is_not_render_ready(): void
    {
        // Requirement 7.2 — no colour set means the Storefront applies none.
        $company = Company::factory()->create(['primary_colour' => null]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertFalse($branding->hasPrimaryColour());
        $this->assertNull($branding->primaryColour);
    }

    // ---- 7.3 Terms & Conditions surfaced at checkout -------------------------

    public function test_checkout_branding_surfaces_terms_text(): void
    {
        // Requirement 7.3 — Terms & Conditions are surfaced for checkout.
        $company = Company::factory()->create([
            'terms_text' => 'You agree to the terms.',
        ]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertTrue($branding->hasTerms());
        $this->assertSame('You agree to the terms.', $branding->termsText);
    }

    public function test_terms_resolve_from_company_even_on_an_event_surface(): void
    {
        // Requirement 7.3 — Terms are a Company checkout setting; an Event page
        // surfaces the Company Terms rather than an Event-level value.
        $company = Company::factory()->create([
            'terms_text' => 'Company checkout terms.',
        ]);
        $event = Event::factory()->for($company)->create();

        $branding = $this->resolver()->forEvent($event);

        $this->assertTrue($branding->hasTerms());
        $this->assertSame('Company checkout terms.', $branding->termsText);
    }

    public function test_checkout_without_terms_is_not_render_ready(): void
    {
        // Requirement 7.3 — no Terms set means nothing to show at checkout.
        $company = Company::factory()->create(['terms_text' => null]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertFalse($branding->hasTerms());
        $this->assertNull($branding->termsText);
    }

    // ---- 7.4 Custom ticket-info fields available for tickets -----------------

    public function test_ticket_fields_are_available_for_ticket_rendering(): void
    {
        // Requirement 7.4 — defined custom fields are available to print on
        // issued tickets.
        $company = Company::factory()->create([
            'ticket_field_defs' => [['label' => 'Seat'], ['label' => 'Table']],
        ]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertTrue($branding->hasTicketFields());
        $this->assertSame(
            [['label' => 'Seat'], ['label' => 'Table']],
            $branding->ticketFieldDefs,
        );
    }

    public function test_no_ticket_fields_means_none_are_printed(): void
    {
        // Requirement 7.4 — with no fields defined, tickets print none.
        $company = Company::factory()->create(['ticket_field_defs' => null]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertFalse($branding->hasTicketFields());
        $this->assertSame([], $branding->ticketFieldDefs);
    }

    // ---- 7.5 Event-level overrides reach the Event surface -------------------

    public function test_event_surface_renders_event_level_logo_and_colour_override(): void
    {
        // Requirement 7.5 — an Event's own logo/colour reach the Event surface
        // in preference to the Company default.
        $company = Company::factory()->create([
            'logo_path' => 'branding/logos/co.png',
            'primary_colour' => '#010203',
        ]);
        $event = Event::factory()->for($company)->create([
            'logo_path' => 'branding/logos/event.png',
            'primary_colour' => '#0A0B0C',
        ]);

        $companyBranding = $this->resolver()->forCompany($company);
        $eventBranding = $this->resolver()->forEvent($event);

        // The Company Storefront still shows the Company branding...
        $this->assertSame('branding/logos/co.png', $companyBranding->logoPath);
        $this->assertSame('#010203', $companyBranding->primaryColour);

        // ...while the Event surface shows the override, and reports render-ready.
        $this->assertTrue($eventBranding->hasLogo());
        $this->assertTrue($eventBranding->hasPrimaryColour());
        $this->assertSame('branding/logos/event.png', $eventBranding->logoPath);
        $this->assertSame('#0A0B0C', $eventBranding->primaryColour);
    }

    public function test_event_surface_renders_event_level_ticket_field_override(): void
    {
        // Requirement 7.5 — Event-level ticket fields reach that Event's tickets.
        $company = Company::factory()->create([
            'ticket_field_defs' => [['label' => 'Seat']],
        ]);
        $event = Event::factory()->for($company)->create([
            'ticket_field_defs' => [['label' => 'VIP Table']],
        ]);

        $branding = $this->resolver()->forEvent($event);

        $this->assertTrue($branding->hasTicketFields());
        $this->assertSame([['label' => 'VIP Table']], $branding->ticketFieldDefs);
    }

    public function test_event_surface_inherits_unset_facets_from_company(): void
    {
        // Requirement 7.5 — facets not overridden at the Event level fall back
        // to the Company so the Event surface still renders complete branding.
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

        $branding = $this->resolver()->forEvent($event);

        // Logo + fields inherited from the Company, colour is the Event override.
        $this->assertTrue($branding->hasLogo());
        $this->assertSame('branding/logos/co.png', $branding->logoPath);
        $this->assertSame('#0A0B0C', $branding->primaryColour);
        $this->assertSame([['label' => 'Seat']], $branding->ticketFieldDefs);
    }

    public function test_event_override_does_not_leak_onto_the_company_storefront(): void
    {
        // Requirement 7.5 — an Event override applies only to that Event's
        // surfaces; the Company Storefront keeps the Company branding.
        $company = Company::factory()->create(['primary_colour' => '#010203']);
        Event::factory()->for($company)->create(['primary_colour' => '#FF0000']);

        $storefront = $this->resolver()->forCompany($company);

        $this->assertSame('#010203', $storefront->primaryColour);
    }

    // ---- Render-readiness contract the surfaces rely on ----------------------

    public function test_fully_unset_branding_reports_nothing_to_render(): void
    {
        // A Company with no branding set surfaces an EffectiveBranding whose
        // render flags are all false, so no surface renders stray branding.
        $company = Company::factory()->create([
            'logo_path' => null,
            'primary_colour' => null,
            'terms_text' => null,
            'ticket_field_defs' => null,
        ]);

        $branding = $this->resolver()->forCompany($company);

        $this->assertInstanceOf(EffectiveBranding::class, $branding);
        $this->assertFalse($branding->hasLogo());
        $this->assertFalse($branding->hasPrimaryColour());
        $this->assertFalse($branding->hasTerms());
        $this->assertFalse($branding->hasTicketFields());
    }
}
