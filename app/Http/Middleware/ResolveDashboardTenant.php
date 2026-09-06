<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SuperAdmin\ImpersonationController;
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
 * Super_Admins (no `company_id`) normally establish no Company here and operate
 * on the separate super-admin surface. But when a Super_Admin has "jumped into"
 * a Company (an `impersonate_company_id` session flag set from `/admin`), this
 * middleware binds THAT Company instead, so the Super_Admin acts inside the
 * dashboard as an admin of the chosen tenant. (Super_Admins already hold every
 * Company ability via the Gate::before hook, so only the tenant binding is
 * needed.) A suspended Company is never bound.
 */
class ResolveDashboardTenant
{
    public function __construct(private TenantContext $tenantContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $this->tenantContext->hasCompany()) {
            $companyId = $this->resolveCompanyId($request, $user);

            if ($companyId !== null) {
                $company = Company::find($companyId);

                if ($company !== null && ! $company->isSuspended()) {
                    $this->tenantContext->setCompany($company);
                }
            }
        }

        return $next($request);
    }

    /**
     * Resolve which Company id should be bound for this dashboard request.
     *
     * A Super_Admin binds the Company they have jumped into (session flag); a
     * Company_User binds their own `company_id`.
     */
    private function resolveCompanyId(Request $request, $user): ?int
    {
        if ($user->isSuperAdmin()) {
            $impersonatedId = $request->session()->get(ImpersonationController::SESSION_KEY);

            return $impersonatedId !== null ? (int) $impersonatedId : null;
        }

        return $user->company_id !== null ? (int) $user->company_id : null;
    }
}
