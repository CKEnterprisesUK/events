<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated Company_User's own Company onto {@see TenantContext}
 * for Company-dashboard requests.
 *
 * The dashboard lives under the reserved `/dashboard` prefix, where the URL
 * carries no `/{company-slug}/` segment and `ResolveTenant` therefore
 * establishes no active Company. Without a tenant, the global `company_id`
 * scope forces `1 = 0` and dashboard queries return nothing. This middleware
 * resolves the tenant from the authenticated user's `company_id` instead, so
 * every Company-owned query is scoped to the user's own Company and
 * cross-Company access is denied. (Requirements 5.1, 3.8, 3.10)
 *
 * Super_Admins (no `company_id`) establish no Company here; they operate on the
 * separate super-admin surface.
 */
class ResolveDashboardTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->company_id !== null && ! $this->tenantContext->hasCompany()) {
            $company = Company::find($user->company_id);

            if ($company !== null) {
                $this->tenantContext->setCompany($company);
            }
        }

        return $next($request);
    }
}
