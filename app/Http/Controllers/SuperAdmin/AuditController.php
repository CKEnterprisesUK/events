<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Concerns\FiltersAuditLogs;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Super-admin Audit surface: the Platform-wide, cross-tenant activity trail.
 *
 * This is the forensic/compliance view. It lives on the reserved `/admin`
 * prefix guarded by `super.admin`, resolves no active Company, and reads the
 * `audit_logs` table across ALL Companies (the table carries no global tenant
 * scope, so a plain query already spans every tenant). On top of the shared
 * category/action/date/text filters it adds a Company filter and an
 * "impersonated only" toggle, so staff can review exactly what was done while
 * jumped into tenants. Read-only — a single GET, no mutation.
 */
class AuditController extends Controller
{
    use FiltersAuditLogs;

    /** Page size for the audit list. */
    private const PER_PAGE = 50;

    /**
     * The Platform-wide audit trail, most recent first, with the shared filters
     * plus a Company filter and an impersonated-only toggle.
     */
    public function index(Request $request): View
    {
        $filters = $this->auditFilters($request);

        $companyId = (int) $request->query('company_id', 0);
        $impersonatedOnly = $request->boolean('impersonated');

        $logs = AuditLog::query()
            ->with(['actor', 'impersonator', 'company'])
            ->tap(fn (Builder $q) => $this->applyAuditFilters($q, $filters))
            ->when($companyId > 0, fn (Builder $q) => $q->where('company_id', $companyId))
            ->when($impersonatedOnly, fn (Builder $q) => $q->where('is_impersonated', true))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,
            'filters' => $filters,
            'companyId' => $companyId,
            'impersonatedOnly' => $impersonatedOnly,
            'categoryOptions' => AuditLog::CATEGORY_LABELS,
            'actionOptions' => $this->auditActionOptions(),
            'companies' => Company::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
