<?php

namespace App\Http\Controllers;

use App\Models\PlatformSetting;
use Illuminate\Contracts\View\View;

/**
 * Serves the Platform's public root Landing_Page.
 *
 * The Landing_Page lives at the reserved root prefix `/`, which establishes no
 * active Company (tenant resolution only runs on `/{company-slug}/...` paths).
 *
 * Requirement 8.1: WHEN a Customer requests events.domain, THE Platform SHALL
 * display the Landing_Page.
 */
class LandingController extends Controller
{
    /**
     * Display the Platform landing page at GET /.
     */
    public function index(): View
    {
        // The live Platform fee percent (Company overrides aside) drives the
        // pricing calculator on the Landing_Page, so the figure shown always
        // matches the current Global_Fee_Percent in platform_settings. The
        // configurable Stripe-fee estimate (percent + fixed) lets the calculator
        // also show an approximate Stripe cut, so "you receive" reflects the real
        // net rather than excluding Stripe entirely. Both come from the DB, never
        // hardcoded. (Configurable-estimate feature)
        $setting = PlatformSetting::current();

        return view('landing', [
            'feePercent' => (float) $setting->global_fee_percent,
            'stripeFeePercent' => (float) $setting->stripe_fee_percent,
            'stripeFeeFixedMinor' => (int) $setting->stripe_fee_fixed_minor,
        ]);
    }
}
