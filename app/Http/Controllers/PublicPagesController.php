<?php

namespace App\Http\Controllers;

use App\Support\PricingViewData;
use Illuminate\Contracts\View\View;

/**
 * The public marketing pages that sit alongside the homepage: Features,
 * Pricing, How it works and For charities. All live at reserved root prefixes
 * (declared before the `/{company-slug}` storefront catch-all) and establish no
 * active Company or auth requirement.
 *
 * The Trust Centre is served separately by {@see TrustController} at `/trust`.
 *
 * Pricing figures on the Pricing page come from the live platform settings via
 * {@see PricingViewData} — the same source the homepage pricing preview uses —
 * so there is a single source of truth for the fee numbers on the public site.
 */
class PublicPagesController extends Controller
{
    /**
     * The Features overview at GET /features.
     */
    public function features(): View
    {
        return view('marketing.features');
    }

    /**
     * The Pricing page and calculator at GET /pricing.
     */
    public function pricing(): View
    {
        return view('marketing.pricing', PricingViewData::forView());
    }

    /**
     * The How it works product journey at GET /how-it-works.
     */
    public function howItWorks(): View
    {
        return view('marketing.how-it-works');
    }

    /**
     * The For charities page at GET /for-charities.
     */
    public function forCharities(): View
    {
        return view('marketing.for-charities');
    }
}
