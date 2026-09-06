<?php

namespace App\Http\Controllers;

use App\Services\Branding\BrandingResolver;
use App\Services\StorefrontListing;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public Company storefront under `/{company-slug}/`.
 *
 * By the time an action runs, `ResolveTenant` has bound the active Company on
 * {@see TenantContext} (or already returned 404 for an unmatched or suspended
 * slug). The index lists the Company's PUBLISHED Events (unpublished Events are
 * excluded so they never appear publicly — Requirements 5.4, 5.5, 8.2), served
 * from a per-Company cache that is invalidated on Event writes
 * ({@see StorefrontListing}). The page is rendered with the Company's resolved
 * branding: its logo and primary colour. (Requirements 7.1, 7.2, 8.2)
 */
class StorefrontController extends Controller
{
    public function index(
        TenantContext $tenantContext,
        StorefrontListing $listing,
        BrandingResolver $branding,
    ): View|Response {
        $company = $tenantContext->company();

        // Defensive: the `tenant` middleware guarantees a resolved Company on
        // this route, but guard against an empty context rather than render a
        // storefront with no tenant.
        abort_unless($company !== null, 404);

        return view('storefront.index', [
            'company' => $company,
            'events' => $listing->forCompany($company),
            'branding' => $branding->forCompany($company),
        ]);
    }
}
