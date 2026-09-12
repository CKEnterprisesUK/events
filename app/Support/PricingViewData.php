<?php

namespace App\Support;

use App\Models\PlatformSetting;

/**
 * Single source of the pricing figures the public marketing pages render.
 *
 * The Global_Fee_Percent and the configurable Stripe-fee estimate (percent +
 * fixed pence) always come from the live `platform_settings` row — never
 * hardcoded — so the homepage pricing preview and the dedicated Pricing page
 * (and its calculator) all show the same, current numbers. The calculator's
 * money maths is a display-only mirror of {@see \App\Services\FeeCalculationService};
 * this helper only surfaces the inputs, it does not re-implement the fee rules.
 */
final class PricingViewData
{
    /**
     * @return array{feePercent: float, stripeFeePercent: float, stripeFeeFixedMinor: int}
     */
    public static function forView(): array
    {
        $setting = PlatformSetting::current();

        return [
            'feePercent' => (float) $setting->global_fee_percent,
            'stripeFeePercent' => (float) $setting->stripe_fee_percent,
            'stripeFeeFixedMinor' => (int) $setting->stripe_fee_fixed_minor,
        ];
    }
}
