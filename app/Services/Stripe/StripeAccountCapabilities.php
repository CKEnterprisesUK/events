<?php

namespace App\Services\Stripe;

/**
 * A snapshot of the capabilities and verification state the Platform cares
 * about on a connected Stripe account. Read from the connected account after
 * onboarding and on `account.updated` webhooks to decide whether the Company
 * may sell paid tickets AND to tell the Company exactly what is still
 * outstanding when the account is restricted. (Requirements 11.3, 11.4)
 *
 * Beyond `chargesEnabled` (the gate on taking paid orders) this carries the
 * fields Stripe exposes for onboarding/verification, so the Payments page can
 * surface compliance errors the Company would otherwise only see inside the
 * Stripe Dashboard:
 *   - `payoutsEnabled` — whether payouts are enabled (distinct from charges).
 *   - `detailsSubmitted` — whether all onboarding information has been provided.
 *   - `disabledReason` — Stripe's reason the account is disabled/restricted,
 *     null when not disabled.
 *   - `currentlyDue` / `pastDue` / `pendingVerification` — the connected
 *     account's outstanding requirement identifiers (Stripe `requirements.*`).
 *   - `errors` — Stripe's human-facing verification errors, each a
 *     `['requirement' => ..., 'code' => ..., 'reason' => ...]` map.
 */
final class StripeAccountCapabilities
{
    /**
     * @param  list<string>  $currentlyDue  Requirement ids Stripe needs now.
     * @param  list<string>  $pastDue  Overdue requirement ids (account restricted).
     * @param  list<string>  $pendingVerification  Ids Stripe is currently reviewing
     *                                             (e.g. an uploaded document).
     * @param  list<array{requirement: string, code: string, reason: string}>  $errors
     *                                                                                  Human-facing verification errors.
     */
    public function __construct(
        public readonly string $accountId,
        public readonly bool $chargesEnabled,
        public readonly bool $payoutsEnabled = false,
        public readonly bool $detailsSubmitted = false,
        public readonly ?string $disabledReason = null,
        public readonly array $currentlyDue = [],
        public readonly array $pastDue = [],
        public readonly array $pendingVerification = [],
        public readonly array $errors = [],
    ) {}

    /**
     * The requirements payload as persisted on the Company (`stripe_requirements`
     * JSON) and rendered on the Payments page. Keeps the four requirement facets
     * together so the controller/webhook store a single structured value.
     *
     * @return array{
     *     currently_due: list<string>,
     *     past_due: list<string>,
     *     pending_verification: list<string>,
     *     errors: list<array{requirement: string, code: string, reason: string}>
     * }
     */
    public function requirements(): array
    {
        return [
            'currently_due' => $this->currentlyDue,
            'past_due' => $this->pastDue,
            'pending_verification' => $this->pendingVerification,
            'errors' => $this->errors,
        ];
    }
}
