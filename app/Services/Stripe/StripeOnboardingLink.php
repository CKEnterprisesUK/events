<?php

namespace App\Services\Stripe;

/**
 * The result of starting Connect Standard onboarding: the connected account id
 * that has been created/reused for the Company (stored as
 * `companies.stripe_account_id`) and the URL the Owner is redirected to in
 * order to complete onboarding. (Requirements 11.1, 11.2)
 */
final class StripeOnboardingLink
{
    public function __construct(
        public readonly string $accountId,
        public readonly string $url,
    ) {}
}
