<?php

namespace App\Services\Stripe;

/**
 * The actual card-processing fee Stripe took for a charge on a connected
 * account, read from the charge's Balance Transaction, reduced to the fields
 * the Platform needs. This is Stripe's OWN fee (deducted inside the connected
 * account), NOT the Platform's `application_fee_amount`. (Truthful-payout
 * feature)
 *
 * `feeMinor` is the total Stripe fee in integer minor currency units. `currency`
 * is the balance transaction's currency (lower-case ISO code, as Stripe returns
 * it). `chargeId` is the charge the fee was read from, so the caller can record
 * the linkage on the Order.
 */
final class StripeChargeFee
{
    public function __construct(
        public readonly string $chargeId,
        public readonly int $feeMinor,
        public readonly string $currency,
    ) {}
}
