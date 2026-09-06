<?php

namespace App\Http\Controllers;

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
        return view('landing');
    }
}
