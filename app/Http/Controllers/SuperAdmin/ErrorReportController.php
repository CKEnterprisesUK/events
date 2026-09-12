<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ErrorReport;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Super-admin Error reports surface: the lookup for uncaught production 500s.
 *
 * In production (`APP_DEBUG=false`) the exception handler stores every uncaught
 * server error in `error_reports` with a short customer-facing reference and
 * shows the visitor a branded page carrying only that reference. This surface
 * is where a Super_Admin pastes the reference a customer quoted and sees the
 * full exception behind it — class, message, file/line, request, and trace.
 *
 * It lives on the reserved `/admin` prefix guarded by `super.admin`, resolves
 * no active Company, and reads `error_reports` across ALL Companies (the table
 * carries no global tenant scope). The only mutation is toggling a report's
 * resolved flag so staff can keep the queue tidy.
 */
class ErrorReportController extends Controller
{
    /** Page size for the error list. */
    private const PER_PAGE = 50;

    /**
     * The platform-wide error list, most recent first, with a reference/URL/
     * message search, a company filter, and an unresolved-only toggle.
     */
    public function index(Request $request): View
    {
        $companyId = (int) $request->query('company_id', 0);
        $unresolvedOnly = $request->boolean('unresolved');
        $search = trim((string) $request->query('q', ''));

        $reports = ErrorReport::query()
            ->with('company')
            ->when($companyId > 0, fn (Builder $q) => $q->where('company_id', $companyId))
            ->when($unresolvedOnly, fn (Builder $q) => $q->whereNull('resolved_at'))
            ->when($search !== '', function (Builder $q) use ($search) {
                $q->where(function (Builder $inner) use ($search) {
                    $inner->where('reference', 'like', '%'.$search.'%')
                        ->orWhere('url', 'like', '%'.$search.'%')
                        ->orWhere('message', 'like', '%'.$search.'%')
                        ->orWhere('exception_class', 'like', '%'.$search.'%');
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.errors.index', [
            'reports' => $reports,
            'companyId' => $companyId,
            'unresolvedOnly' => $unresolvedOnly,
            'search' => $search,
            'companies' => Company::query()->orderBy('name')->pluck('name', 'id'),
            'unresolvedCount' => ErrorReport::query()->whereNull('resolved_at')->count(),
        ]);
    }

    /**
     * Show one error report in full — the stored exception, request context and
     * complete stack trace behind a customer-quoted reference.
     */
    public function show(int $errorReport): View
    {
        $report = ErrorReport::query()
            ->with(['company', 'user'])
            ->findOrFail($errorReport);

        return view('admin.errors.show', [
            'report' => $report,
        ]);
    }

    /**
     * Toggle a report's resolved flag. `resolve` stamps `resolved_at`;
     * `reopen` clears it. The only mutation on this surface.
     */
    public function updateStatus(Request $request, int $errorReport): RedirectResponse
    {
        $report = ErrorReport::query()->findOrFail($errorReport);

        $data = $request->validate([
            'action' => ['required', 'in:resolve,reopen'],
        ]);

        if ($data['action'] === 'resolve') {
            $report->update(['resolved_at' => now()]);
            $message = 'Error report marked resolved.';
        } else {
            $report->update(['resolved_at' => null]);
            $message = 'Error report reopened.';
        }

        return redirect()
            ->route('admin.errors.show', $report->getKey())
            ->with('status', $message);
    }
}
