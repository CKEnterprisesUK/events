<?php

namespace App\Services\Branding;

/**
 * The resolved, effective branding for a surface (a Company Storefront, or an
 * Event page / ticket). It carries the concrete logo, poster/hero image,
 * primary colour, and Terms & Conditions text that should be applied, after
 * Event-level overrides have been layered over the Company-level defaults.
 * (Requirements 7.1–7.5)
 *
 * This is an immutable value object: the {@see BrandingResolver} produces it and
 * the Storefront/event pages, checkout, and ticket rendering read from it.
 *
 * Logos are exposed three ways so surfaces can present them richly:
 *   - {@see $logoPath}         the resolved primary logo (Event override else
 *     Company) — the safe default used for the favicon and tickets;
 *   - {@see $companyLogoPath}  the Company logo specifically;
 *   - {@see $eventLogoPath}    the Event's own logo override, if any.
 * An Event page can therefore show the event logo AND the organiser's company
 * logo when they differ, rather than only one.
 */
final class EffectiveBranding
{
    public function __construct(
        public readonly ?string $logoPath,
        public readonly ?string $primaryColour,
        public readonly ?string $termsText,
        public readonly ?string $posterPath = null,
        public readonly ?string $companyLogoPath = null,
        public readonly ?string $eventLogoPath = null,
    ) {}

    /**
     * Whether a (resolved) logo is set for this surface. (Requirement 7.1)
     */
    public function hasLogo(): bool
    {
        return $this->logoPath !== null && $this->logoPath !== '';
    }

    /**
     * Whether a poster/hero image is set for this surface.
     */
    public function hasPoster(): bool
    {
        return $this->posterPath !== null && $this->posterPath !== '';
    }

    /**
     * Whether the owning Company has its own logo.
     */
    public function hasCompanyLogo(): bool
    {
        return $this->companyLogoPath !== null && $this->companyLogoPath !== '';
    }

    /**
     * Whether the Event defines its own logo override (distinct from any
     * Company logo it would otherwise inherit).
     */
    public function hasEventLogo(): bool
    {
        return $this->eventLogoPath !== null && $this->eventLogoPath !== '';
    }

    /**
     * Whether a primary brand colour is set for this surface. (Requirement 7.2)
     */
    public function hasPrimaryColour(): bool
    {
        return $this->primaryColour !== null && $this->primaryColour !== '';
    }

    /**
     * Whether Terms & Conditions text is set to show at checkout. (7.3)
     */
    public function hasTerms(): bool
    {
        return $this->termsText !== null && $this->termsText !== '';
    }
}
