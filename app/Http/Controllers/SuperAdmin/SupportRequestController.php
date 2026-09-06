<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\SupportRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Super-admin support-ticket queue: the CK Enterprises operator view of every
 * in-dashboard "Contact support" request raised across the whole Platform.
 *
 * Deliberately minimal by design: operators can read a ticket and open/close
 * it — there is no in-app reply/comment thread (support conversations happen
 * over email, seeded by the notification sent when the ticket is raised).
 *
 * Not tenant-scoped — a Super_Admin operates across every Company. Because
 * {@see SupportRequest} carries the global `company_id` tenant scope (which,
 * with no resolved Company on `/admin`, would hide every row), all reads here
 * use `withoutGlobalScopes()` and resolve the owning Company name separately.
 */
class SupportRequestController extends Controller
{
    /**
     * List support tickets across all Companies, newest first, with an
     * optional status filter (defaults to showing open tickets first). Company
     * names are resolved in one lookup and folded onto each row.
     */
    public function index(Request $request): View
    {
        $filter = $request->query('status');
        $validFilters = ['open', 'closed', 'all'];
        if (! in_array($filter, $validFilters, true)) {
            $filter = 'open';
        }

        $query = SupportRequest::query()
            ->withoutGlobalScopes()
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'resolved', 'closed')")
            ->orderByDesc('id');

        if ($filter === 'open') {
            $query->whereIn('status', [SupportRequest::STATUS_OPEN, SupportRequest::STATUS_IN_PROGRESS]);
        } elseif ($filter === 'closed') {
            $query->whereIn('status', [SupportRequest::STATUS_CLOSED, SupportRequest::STATUS_RESOLVED]);
        }

        $tickets = $query->get();

        $companyNames = Company::query()->pluck('name', 'id');

        // Counts for the filter tabs, independent of the current filter.
        $openCount = SupportRequest::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [SupportRequest::STATUS_OPEN, SupportRequest::STATUS_IN_PROGRESS])
            ->count();
        $totalCount = SupportRequest::query()->withoutGlobalScopes()->count();

        return view('admin.support.index', [
            'tickets' => $tickets,
            'companyNames' => $companyNames,
            'filter' => $filter,
            'openCount' => $openCount,
            'closedCount' => $totalCount - $openCount,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * Show a single ticket in full, with the owning Company and raiser.
     */
    public function show(int $supportRequest): View
    {
        $ticket = SupportRequest::query()
            ->withoutGlobalScopes()
            ->with('user')
            ->findOrFail($supportRequest);

        $company = Company::find($ticket->company_id);

        return view('admin.support.show', [
            'ticket' => $ticket,
            'company' => $company,
        ]);
    }

    /**
     * Open or close a ticket. This is the only mutation on the surface: `close`
     * marks it closed with a `resolved_at` stamp; `reopen` returns it to open
     * and clears the stamp. Any other action is ignored.
     */
    public function updateStatus(Request $request, int $supportRequest): RedirectResponse
    {
        $ticket = SupportRequest::query()
            ->withoutGlobalScopes()
            ->findOrFail($supportRequest);

        $data = $request->validate([
            'action' => ['required', 'in:close,reopen'],
        ]);

        if ($data['action'] === 'close') {
            $ticket->update([
                'status' => SupportRequest::STATUS_CLOSED,
                'resolved_at' => now(),
            ]);
            $message = 'Ticket closed.';
        } else {
            $ticket->update([
                'status' => SupportRequest::STATUS_OPEN,
                'resolved_at' => null,
            ]);
            $message = 'Ticket reopened.';
        }

        return redirect()
            ->route('admin.support.show', $ticket->getKey())
            ->with('status', $message);
    }
}
