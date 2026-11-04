<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * Update a Company's registration identity (legal name, organisation type,
     * Companies House / Charity Commission numbers). Super_Admin only: Company
     * users cannot edit these from their own settings.
     */
    public function updateRegistration(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'organisation_type' => ['required', Rule::in(array_keys(Company::ORGANISATION_TYPES))],
            'company_number' => [
                'nullable',
                'string',
                'max:50',
                Rule::requiredIf(fn (): bool => in_array(
                    $request->input('organisation_type'),
                    [Company::TYPE_COMPANY, Company::TYPE_CIC],
                    true,
                )),
            ],
            // Optional even for charities (small/excepted/exempt charities).
            'charity_number' => ['nullable', 'string', 'max:50'],
        ]);

        $company->update([
            'legal_name' => $data['legal_name'],
            'organisation_type' => $data['organisation_type'],
            'company_number' => $data['company_number'] ?? null,
            'charity_number' => $data['charity_number'] ?? null,
        ]);

        $this->audit->record(
            action: AuditLog::COMPANY_REGISTRATION_UPDATED,
            auditable: $company,
            summary: 'Updated registration details for '.$company->name,
        );

        return redirect()
            ->route('admin.clients.show', $company)
            ->with('status', __('Registration details updated.'));
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
