<?php

namespace App\Services\Stripe;

/**
 * A refund issued against a charge on a connected account, reduced to the
 * fields the Platform needs. Used by the refund flow in a later task (task 20);
 * defined here so the Stripe boundary is complete. (Requirement 17.2)
 */
final class StripeRefund
{
    public function __construct(
        public readonly string $id,
        public readonly int $amountMinor,
        public readonly string $status,
    ) {}
}
