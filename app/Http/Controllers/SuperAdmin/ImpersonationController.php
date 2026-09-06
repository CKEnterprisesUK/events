<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\AuditLogger;
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

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Enter a Company's dashboard as the acting Super_Admin.
     */
    public function start(Request $request, Company $company): RedirectResponse
    {
        // A suspended Company cannot be operated; steer the Super_Admin back to
        // the companies list to unsuspend it first.
        if ($company->isSuspended()) {
            return redirect()
                ->route('admin.clients.show', $company)
                ->withErrors(['company' => __('That company is suspended. Unsuspend it before jumping in.')]);
        }

        $request->session()->put(self::SESSION_KEY, $company->getKey());

        // Record the jump-in against the target Company so its activity trail
        // (and the platform trail) shows exactly when staff entered the tenant.
        // The flag is now set, so the logger stamps this as an impersonated
        // Super_Admin action. (Accountability — audit trail)
        $this->audit->record(
            action: AuditLog::IMPERSONATION_STARTED,
            auditable: $company,
            summary: 'Super admin started impersonating '.$company->name,
            companyId: (int) $company->getKey(),
        );

        return redirect()->route('dashboard.home');
    }

    /**
     * Leave the impersonated Company and return to the platform surface.
     */
    public function stop(Request $request): RedirectResponse
    {
        // Capture which Company was being impersonated BEFORE clearing the flag,
        // both to name it in the trail and so the logger still detects this as
        // an impersonated action while recording the stop.
        $companyId = $request->session()->get(self::SESSION_KEY);

        if ($companyId !== null) {
            $company = Company::find((int) $companyId);

            $this->audit->record(
                action: AuditLog::IMPERSONATION_STOPPED,
                auditable: $company,
                summary: $company !== null
                    ? 'Super admin stopped impersonating '.$company->name
                    : 'Super admin stopped impersonating',
                companyId: (int) $companyId,
            );
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('admin.home');
    }
}
