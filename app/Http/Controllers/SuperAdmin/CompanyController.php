<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

/**
 * Super-admin controller for toggling a Company's suspension state. The
 * Company list and per-Company detail now live on the Clients surface
 * ({@see ClientController}); this controller only performs the suspend /
 * unsuspend actions, redirecting back to that Company's client page.
 * (Requirements 20.3, 20.4)
 *
 * This lives on the separate super-admin surface (reserved `/admin` prefix,
 * guarded by `super.admin` on `is_super_admin`, resolving no Company). It is
 * intentionally NOT tenant-scoped: a Super_Admin sees and administers every
 * Company on the Platform. The `Company` model carries no tenant global scope
 * (it is the tenant itself), so a plain query returns all Companies.
 *
 * Suspend/unsuspend simply persist `companies.status`. Enforcement lives
 * elsewhere and reads the live status on each request: `ResolveTenant` 404s a
 * suspended Company's storefront/event pages, and `EnsureCompanyActive` blocks
 * a suspended Company's Company_User logins and existing sessions. So a
 * suspension (or its reversal) takes effect on the very next request with no
 * further action here. (Requirements 2.1–2.5, 20.3, 20.4)
 */
class CompanyController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Mark a Company as a Suspended_Company. (Requirement 20.3)
     */
    public function suspend(Company $company): RedirectResponse
    {
        $company->update(['status' => Company::STATUS_SUSPENDED]);

        $this->audit->record(
            action: AuditLog::COMPANY_SUSPENDED,
            auditable: $company,
            summary: 'Suspended '.$company->name,
        );

        return redirect()
            ->route('admin.clients.show', $company)
            ->with('status', __(':name suspended.', ['name' => $company->name]));
    }

    /**
     * Remove the suspended status from a Company. (Requirement 20.4)
     */
    public function unsuspend(Company $company): RedirectResponse
    {
        $company->update(['status' => Company::STATUS_ACTIVE]);

        $this->audit->record(
            action: AuditLog::COMPANY_UNSUSPENDED,
            auditable: $company,
            summary: 'Unsuspended '.$company->name,
        );

        return redirect()
            ->route('admin.clients.show', $company)
            ->with('status', __(':name unsuspended.', ['name' => $company->name]));
    }
}
