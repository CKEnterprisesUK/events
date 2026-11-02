<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\Event;

/**
 * Computes the new-customer onboarding checklist for a Company: the four
 * initial setup steps a newly registered Company works through before it can
 * take money and sell tickets —
 *
 *   1. Connect Stripe so the Company can get paid.
 *   2. Set the support and GDPR/data-protection contacts.
 *   3. Choose the customer-data retention window.
 *   4. Sort the Company branding.
 *   5. Create the first Event.
 *
 * Each step's completion is derived from the Company's own persisted state, so
 * the checklist reflects reality without any separate progress flags to keep in
 * sync. Once every step is complete the resulting {@see OnboardingProgress}
 * reports complete and the dashboard stops showing it.
 *
 * The Event count is scoped explicitly to the given Company and bypasses the
 * tenant global scope, mirroring the DashboardController which runs outside the
 * `dashboard.tenant` binding.
 */
class OnboardingChecklist
{
    /**
     * Build the checklist progress for the given Company.
     */
    public function for(Company $company): OnboardingProgress
    {
        return new OnboardingProgress([
            new OnboardingStep(
                key: 'stripe',
                title: 'Connect Stripe',
                description: 'Link your Stripe account so you can get paid for ticket sales.',
                complete: $this->hasStripe($company),
                routeName: 'dashboard.stripe.status',
                actionLabel: 'Connect Stripe',
            ),
            new OnboardingStep(
                key: 'contacts',
                title: 'Add your contacts',
                description: 'Set your support contact and GDPR/data-protection contact.',
                complete: $this->hasContacts($company),
                routeName: 'dashboard.branding.edit',
                actionLabel: 'Add contacts',
            ),
            new OnboardingStep(
                key: 'retention',
                title: 'Set data retention',
                description: 'Choose how long you keep customer personal data before it is automatically anonymised.',
                complete: $this->hasConfirmedRetention($company),
                routeName: 'dashboard.branding.edit',
                actionLabel: 'Set retention',
            ),
            new OnboardingStep(
                key: 'branding',
                title: 'Sort your branding',
                description: 'Add a logo, brand colour or terms so your storefront looks like you.',
                complete: $this->hasBranding($company),
                routeName: 'dashboard.branding.edit',
                actionLabel: 'Set branding',
            ),
            new OnboardingStep(
                key: 'event',
                title: 'Create an event',
                description: 'Set up your first event and its tickets to start selling.',
                complete: $this->hasEvent($company),
                routeName: 'dashboard.events.index',
                actionLabel: 'Create an event',
            ),
        ]);
    }

    /**
     * Stripe is considered connected once the connected account reports charges
     * enabled — i.e. the Company can actually take money. (Requirement 11.3)
     */
    private function hasStripe(Company $company): bool
    {
        return (bool) $company->stripe_charges_enabled;
    }

    /**
     * Contacts are set once BOTH the support and GDPR contact emails are present.
     */
    private function hasContacts(Company $company): bool
    {
        return $this->filled($company->support_email)
            && $this->filled($company->gdpr_contact_email);
    }

    /**
     * Retention is considered set once the Owner has EXPLICITLY confirmed a
     * customer-data retention choice (a stamped `retention_confirmed_at`). The
     * column defaults to a safe 24-month window, so completion deliberately
     * keys off the explicit confirmation rather than the mere presence of a
     * value — a silent default is not a deliberate decision.
     */
    private function hasConfirmedRetention(Company $company): bool
    {
        return $company->hasConfirmedRetention();
    }

    /**
     * Branding is considered sorted once the Company has set any one of a logo,
     * a primary colour, or Terms & Conditions text. (Requirements 7.1–7.3)
     */
    private function hasBranding(Company $company): bool
    {
        return $this->filled($company->logo_path)
            || $this->filled($company->primary_colour)
            || $this->filled($company->terms_text);
    }

    /**
     * The Company has created at least one Event. Scoped explicitly to the
     * Company and bypassing the tenant global scope (the dashboard runs outside
     * the tenant binding).
     */
    private function hasEvent(Company $company): bool
    {
        return Event::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->exists();
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
