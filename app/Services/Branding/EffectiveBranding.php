<?php

namespace App\Services\Branding;

/**
 * The resolved, effective branding for a surface (a Company Storefront, or an
 * Event page / ticket). It carries the concrete logo, primary colour, Terms &
 * Conditions text, and custom ticket-info field definitions that should be
 * applied, after Event-level overrides have been layered over the Company-level
 * defaults. (Requirements 7.1–7.5)
 *
 * This is an immutable value object: the {@see BrandingResolver} produces it and
 * the Storefront/event pages, checkout, and ticket rendering read from it.
 *
 * @phpstan-type TicketFieldDefs list<array<string, mixed>>
 */
final class EffectiveBranding
{
    /**
     * @param  list<array<string, mixed>>  $ticketFieldDefs  the custom ticket
     *   information field definitions printed on tickets (Requirement 7.4).
     */
    public function __construct(
        public readonly ?string $logoPath,
        public readonly ?string $primaryColour,
        public readonly ?string $termsText,
        public readonly array $ticketFieldDefs,
    ) {}

    /**
     * Whether a logo is set for this surface. (Requirement 7.1)
     */
    public function hasLogo(): bool
    {
        return $this->logoPath !== null && $this->logoPath !== '';
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

    /**
     * Whether any custom ticket information fields are defined. (7.4)
     */
    public function hasTicketFields(): bool
    {
        return $this->ticketFieldDefs !== [];
    }
}
