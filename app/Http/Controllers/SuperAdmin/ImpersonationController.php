<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets a Super_Admin "jump into" a Company's dashboard and operate there as an
 * admin, then step back out to the platform surface.
 *
 * Impersonation is expressed purely as a session flag: `impersonate_company_id`
 * records the Company a Super_Admin is currently acting as. While it is set,
 * {@see \App\Http\Middleware\ResolveDashboardTenant} binds that Company onto the
 * TenantContext for `/dashboard/*` requests, so the global `company_id` scope
 * constrains every dashboard query to it. A Super_Admin already passes every
 * Company ability via the `Gate::before` hook in AppServiceProvider, so no role
 * grant is needed — only the tenant binding.
 *
 * Access is guarded by the same `super.admin` middleware as the rest of the
 * `/admin` surface, so only a Super_Admin can start or stop impersonation.
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonate_company_id';

    /**
     * Enter a Company's dashboard as the acting Super_Admin.
     */
    public function start(Request $request, Company $company): RedirectResponse
    {
        // A suspended Company cannot be operated; steer the Super_Admin back to
        // the companies list to unsuspend it first.
        if ($company->isSuspended()) {
            return redirect()
                ->route('admin.companies.index')
                ->withErrors(['company' => __('That company is suspended. Unsuspend it before jumping in.')]);
        }

        $request->session()->put(self::SESSION_KEY, $company->getKey());

        return redirect()->route('dashboard.home');
    }

    /**
     * Leave the impersonated Company and return to the platform surface.
     */
    public function stop(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.home');
    }
}
