<?php

namespace App\Services\Branding;

use App\Models\Company;
use App\Models\Event;

/**
 * Resolves the effective branding for a surface by layering Event-level
 * overrides over Company-level defaults.
 *
 * Branding is a Company setting (logo, primary colour, Terms & Conditions, and
 * custom ticket-info fields). An Event may override any of these individually;
 * where an Event-level value is set it wins, otherwise the surface falls back to
 * the Company-level value. This is the single source of truth the Storefront,
 * event pages, checkout, and ticket rendering read from. (Requirement 7.5)
 *
 * Each branding facet is resolved independently: an Event that only overrides
 * the primary colour still inherits the Company logo, Terms, and ticket fields.
 * A T&Cs surface is a Company-level concern (Terms are shown at checkout), so
 * Terms resolve from the Company only. Logo, colour, and ticket fields resolve
 * per Event with Company fallback.
 */
class BrandingResolver
{
    /**
     * The effective branding for a Company Storefront (no Event context): the
     * Company-level logo, colour, Terms, and ticket fields as-is. (7.1–7.4)
     */
    public function forCompany(Company $company): EffectiveBranding
    {
        return new EffectiveBranding(
            logoPath: $this->nullIfBlank($company->logo_path),
            primaryColour: $this->nullIfBlank($company->primary_colour),
            termsText: $this->nullIfBlank($company->terms_text),
            ticketFieldDefs: $this->normaliseFieldDefs($company->ticket_field_defs),
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
        $companyFields = $company?->ticket_field_defs;

        return new EffectiveBranding(
            logoPath: $this->coalesce($event->logo_path, $companyLogo),
            primaryColour: $this->coalesce($event->primary_colour, $companyColour),
            // Terms are a Company checkout setting; Events do not override them.
            termsText: $this->nullIfBlank($companyTerms),
            ticketFieldDefs: $this->resolveFieldDefs($event->ticket_field_defs, $companyFields),
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
     * Resolve custom ticket-info field definitions: Event overrides win when the
     * Event defines any fields, otherwise fall back to the Company's fields.
     * (Requirements 7.4, 7.5)
     *
     * @param  mixed  $eventDefs
     * @param  mixed  $companyDefs
     * @return list<array<string, mixed>>
     */
    private function resolveFieldDefs($eventDefs, $companyDefs): array
    {
        $event = $this->normaliseFieldDefs($eventDefs);

        if ($event !== []) {
            return $event;
        }

        return $this->normaliseFieldDefs($companyDefs);
    }

    /**
     * Normalise a stored `ticket_field_defs` value (array cast, or null) into a
     * clean list of field definitions.
     *
     * @param  mixed  $defs
     * @return list<array<string, mixed>>
     */
    private function normaliseFieldDefs($defs): array
    {
        if (! is_array($defs)) {
            return [];
        }

        return array_values(array_filter($defs, static fn ($def) => is_array($def) && $def !== []));
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
