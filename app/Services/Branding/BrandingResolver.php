<?php

namespace App\Services\Branding;

use App\Models\Company;
use App\Models\Event;

/**
 * Resolves the effective branding for a surface by layering Event-level
 * overrides over Company-level defaults.
 *
 * Branding is a Company setting (logo, primary colour, Terms & Conditions). An
 * Event may override any of these individually; where an Event-level value is
 * set it wins, otherwise the surface falls back to the Company-level value.
 * This is the single source of truth the Storefront, event pages, checkout, and
 * ticket rendering read from. (Requirement 7.5)
 *
 * Each branding facet is resolved independently: an Event that only overrides
 * the primary colour still inherits the Company logo and Terms. A T&Cs surface
 * is a Company-level concern (Terms are shown at checkout), so Terms resolve
 * from the Company only. Logo and colour resolve per Event with Company
 * fallback.
 */
class BrandingResolver
{
    /**
     * The effective branding for a Company Storefront (no Event context): the
     * Company-level logo, colour, and Terms as-is. (7.1–7.3)
     */
    public function forCompany(Company $company): EffectiveBranding
    {
        $companyLogo = $this->nullIfBlank($company->logo_path);

        return new EffectiveBranding(
            logoPath: $companyLogo,
            primaryColour: $this->nullIfBlank($company->primary_colour),
            termsText: $this->nullIfBlank($company->terms_text),
            posterPath: $this->nullIfBlank($company->poster_path),
            companyLogoPath: $companyLogo,
            // No Event context on a Storefront, so there is no event logo.
            eventLogoPath: null,
        );
    }

    /**
     * The effective branding for an Event page / ticket: Event-level overrides
     * where set, falling back to the Event's owning Company otherwise. Terms &
     * Conditions are a Company-level setting shown at checkout, so they always
     * resolve from the Company. (Requirement 7.5)
     */
    public function forEvent(Event $event): EffectiveBranding
    {
        $company = $event->company;

        $companyLogo = $company?->logo_path;
        $companyColour = $company?->primary_colour;
        $companyTerms = $company?->terms_text;
        $companyPoster = $company?->poster_path;

        return new EffectiveBranding(
            logoPath: $this->coalesce($event->logo_path, $companyLogo),
            primaryColour: $this->coalesce($event->primary_colour, $companyColour),
            // Terms are a Company checkout setting; Events do not override them.
            termsText: $this->nullIfBlank($companyTerms),
            // The poster/hero: Event override else the Company Storefront hero.
            posterPath: $this->coalesce($event->poster_path, $companyPoster),
            // Expose the Company and Event logos separately so the Event page
            // can show both when they differ, not just the resolved one.
            companyLogoPath: $this->nullIfBlank($companyLogo),
            eventLogoPath: $this->nullIfBlank($event->logo_path),
        );
    }

    /**
     * The Event-level value when set (non-blank), otherwise the Company-level
     * value. (Requirement 7.5)
     */
    private function coalesce(?string $eventValue, ?string $companyValue): ?string
    {
        return $this->nullIfBlank($eventValue) ?? $this->nullIfBlank($companyValue);
    }

    /**
     * Treat empty strings as "not set" so a blank override falls through to the
     * Company-level value rather than blanking the surface.
     */
    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $value;
    }
}
