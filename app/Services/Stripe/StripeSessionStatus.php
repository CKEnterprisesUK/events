<?php

namespace App\Services\Stripe;

/**
 * The payment-relevant status of a Stripe Checkout Session, read back from
 * Stripe so the reservation sweeper can decide — authoritatively — whether a
 * reserved Order's held capacity may be released or must be kept/confirmed.
 * (Reservation-vs-payment race fix)
 *
 * Reduced to exactly what the sweeper needs:
 *
 *   - `paid`      — the Session completed and its payment succeeded (Stripe
 *                   `payment_status === 'paid'`, or a `complete` session whose
 *                   PaymentIntent succeeded). The seat MUST NOT be released; the
 *                   Order should be confirmed instead.
 *   - `open`      — the Session is still open and payable. The customer may yet
 *                   pay, so the seat MUST stay held.
 *   - `expired`   — the Session is genuinely expired/unpaid at Stripe. The hold
 *                   may be released.
 *   - `unknown`   — Stripe could not be reached, or returned something we cannot
 *                   classify. FAIL SAFE: the caller MUST NOT release (a held
 *                   seat is recoverable; an oversold one is not).
 *
 * `paymentIntentId` carries the Session's PaymentIntent id when present, so a
 * `paid` Session can be confirmed and have its fee/charge resolved without a
 * second lookup.
 */
final class StripeSessionStatus
{
    public const PAID = 'paid';

    public const OPEN = 'open';

    public const EXPIRED = 'expired';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $status,
        public readonly ?string $paymentIntentId = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isExpired(): bool
    {
        return $this->status === self::EXPIRED;
    }

    /**
     * Whether it is SAFE to release the held capacity for this Session. True
     * only when Stripe positively confirms the Session is expired/unpaid.
     * `open`, `paid`, and `unknown` all return false — the fail-safe default is
     * to keep holding the seat. (Reservation-vs-payment race fix)
     */
    public function isSafeToRelease(): bool
    {
        return $this->status === self::EXPIRED;
    }
}
