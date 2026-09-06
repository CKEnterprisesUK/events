<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FiltersAuditLogs;
use App\Models\AuditLog;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Company-dashboard "Activity" surface: a read-only, paginated view of the
 * Company's own audit trail (who did what, when, and — for staff who jumped in
 * — flagged as impersonated).
 *
 * Gated on `ACTION_VIEW_AUDIT_LOG`, which the role matrix grants to the Owner
 * and Admin only (it can reveal sensitive money/access/privacy patterns).
 *
 * The `audit_logs` table is deliberately NOT tenant-scoped by the global
 * `company_id` scope (system/webhook rows have no tenant, and the super-admin
 * surface reads cross-tenant). This surface therefore scopes EXPLICITLY to the
 * acting Company resolved onto the {@see TenantContext} by `dashboard.tenant`,
 * so an organiser only ever sees their own Company's activity.
 */
class AuditLogController extends Controller
{
    use FiltersAuditLogs;

    /** Page size for the activity list. */
    private const PER_PAGE = 25;

    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * The Company's activity trail, most recent first, with category / action /
     * date-range / free-text filters.
     */
    public function index(Request $request): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_AUDIT_LOG);

        $companyId = $this->tenantContext->companyId();

        $filters = $this->auditFilters($request);

        $logs = AuditLog::query()
            ->with(['actor', 'impersonator'])
            // Explicit tenant scoping: the table has no global company scope.
            ->where('company_id', $companyId)
            ->tap(fn (Builder $q) => $this->applyAuditFilters($q, $filters))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('dashboard.activity.index', [
            'logs' => $logs,
            'filters' => $filters,
            'categoryOptions' => AuditLog::CATEGORY_LABELS,
            'actionOptions' => $this->auditActionOptions(),
        ]);
    }
}
