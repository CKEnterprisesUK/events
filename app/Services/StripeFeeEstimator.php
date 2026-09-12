<?php

namespace App\Services;

use App\Models\PlatformSetting;

/**
 * Produces an ESTIMATE of Stripe's own card-processing fee for display before a
 * payment settles — the pre-purchase calculator on the landing page and the
 * checkout preview. (Configurable-estimate feature)
 *
 * The estimate is `round-half-up(amount × percent / 100) + fixed`, using the
 * configurable `stripe_fee_percent` and `stripe_fee_fixed_minor` from
 * `platform_settings` (DB-configured by a Super_Admin, never hardcoded in
 * .env/config). It intentionally mirrors the integer, half-up rounding style of
 * {@see FeeCalculationService} so the estimate is stable and reproducible on the
 * front end.
 *
 * This is ONLY an estimate. The exact fee Stripe took on a real order is read
 * from that charge's balance transaction and stored on the Order
 * (`stripe_fee_minor`); realised dashboards and reports use that actual figure,
 * not this estimate. A zero-amount (free) charge incurs no Stripe fee.
 */
class StripeFeeEstimator
{
    /**
     * Estimate the Stripe fee on a charge of `$amountMinor` (the amount the
     * customer is actually charged, i.e. the order total), in integer minor
     * units. Reads the current platform estimate settings. Returns 0 for a
     * zero/negative amount (a free order is never charged, so Stripe takes
     * nothing).
     */
    public function estimateForAmount(int $amountMinor): int
    {
        $setting = PlatformSetting::current();

        return $this->estimate(
            $amountMinor,
            (string) $setting->stripe_fee_percent,
            (int) $setting->stripe_fee_fixed_minor,
        );
    }

    /**
     * Pure estimate from primitive inputs: `round-half-up(amount × percent/100)
     * + fixed`, clamped to be non-negative. Kept separate from the settings read
     * so it is trivially unit-testable and reusable by the front end's mirrored
     * calculation. A zero/negative amount yields 0 (no charge, no fee).
     *
     * @param  int  $amountMinor  the charge amount in integer minor units.
     * @param  string|float|int  $percent  the percent component, e.g. "1.50".
     * @param  int  $fixedMinor  the fixed component in integer minor units.
     */
    public function estimate(int $amountMinor, string|float|int $percent, int $fixedMinor): int
    {
        if ($amountMinor <= 0) {
            return 0;
        }

        // Percent scaled to hundredths-of-a-percent (×100), folded with the
        // percent→fraction conversion (÷100) into a single ÷10000, so the
        // arithmetic stays in integers — the same technique FeeCalculationService
        // uses to avoid binary-float rounding surprises.
        $percentHundredths = (int) floor(((float) $percent * 100) + 0.5);
        $percentHundredths = max(0, $percentHundredths);

        $numerator = $amountMinor * $percentHundredths;
        $percentPart = intdiv($numerator, 10000);

        if (($numerator % 10000) * 2 >= 10000) {
            $percentPart++;
        }

        return max(0, $percentPart + max(0, $fixedMinor));
    }
}
