<?php

namespace App\Services\Stripe;

/**
 * A signature-verified Stripe webhook event, reduced to the fields the Platform
 * needs to process it idempotently: the Stripe event `id` (the idempotency key
 * recorded in `processed_webhooks`), the event `type` (e.g.
 * `checkout.session.completed`, `charge.refunded`, `charge.dispute.created`,
 * `account.updated`), and the decoded event `data` payload (the `data.object`
 * of the Stripe event).
 *
 * Constructed only by {@see StripePaymentService::constructWebhookEvent()},
 * which verifies the signature against the webhook signing secret before
 * returning — so an instance of this class always represents a verified event.
 * (Requirements 19.1, 19.2, 19.3, 19.5)
 */
final class StripeWebhookEvent
{
    /**
     * @param  array<string, mixed>  $data  The event's `data.object` payload.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly array $data = [],
    ) {}
}
