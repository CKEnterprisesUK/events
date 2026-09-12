<?php

namespace App\Http\Controllers;

use App\Support\PricingViewData;
use Illuminate\Contracts\View\View;

/**
 * Serves the Platform's public root Landing_Page (the marketing homepage).
 *
 * The Landing_Page lives at the reserved root prefix `/`, which establishes no
 * active Company (tenant resolution only runs on `/{company-slug}/...` paths).
 *
 * Requirement 8.1: WHEN a Customer requests events.domain, THE Platform SHALL
 * display the Landing_Page.
 *
 * The homepage summarises the product and links through to the dedicated
 * marketing pages (Features, Pricing, How it works, For charities, Trust) which
 * are served by {@see PublicPagesController}. Pricing figures come from the live
 * platform settings via {@see PricingViewData}, shared with the Pricing page so
 * the homepage preview and the full calculator never disagree.
 */
class LandingController extends Controller
{
    /**
     * Display the marketing homepage at GET /.
     */
    public function index(): View
    {
        return view('landing', PricingViewData::forView());
    }
}
