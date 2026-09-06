<?php

namespace App\Services\Stripe;

/**
 * A created Stripe Checkout Session, reduced to the fields the Platform needs:
 * the Stripe session id (persisted on the Order and matched on the
 * `checkout.session.completed` webhook) and the hosted-checkout URL the
 * Customer is redirected to. Used by the checkout flow in a later task
 * (task 15); defined here so the Stripe boundary is complete. (Requirement 12.1)
 */
final class StripeCheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
