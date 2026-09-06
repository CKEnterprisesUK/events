<?php

namespace App\Services\Stripe;

/**
 * A snapshot of the capabilities the Platform cares about on a connected Stripe
 * account. Read from the connected account after onboarding (and on
 * capability-update webhooks in a later task) to decide whether the Company may
 * sell paid tickets. (Requirements 11.3, 11.4)
 */
final class StripeAccountCapabilities
{
    public function __construct(
        public readonly string $accountId,
        public readonly bool $chargesEnabled,
    ) {}
}
