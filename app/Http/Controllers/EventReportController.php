<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventReportService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Company-dashboard controller for the per-Event financial report — the
 * dedicated, read-only view of a single Event's sales, revenue, and payout
 * figures. (Requirements 6.1, 6.2)
 *
 * The action is gated by the `ACTION_VIEW_REPORTS` authorisation Gate, which
 * the role matrix grants only to the Accountant role (and, via the
 * `Gate::before` bypass, Super_Admins) plus the Owner. Any other Company role —
 * Admin, Scanner — is denied with a 403 and sees nothing, matching the
 * read-only reporting slice of the permission matrix. (Requirement 6.7)
 *
 * This controller is deliberately READ-ONLY: it exposes only a GET endpoint and
 * performs no mutations. All figures come from {@see EventReportService}, the
 * single accounting source of truth, so the per-Event report here and the
 * company-wide report can never diverge. (Requirement 6.2)
 *
 * The Event is resolved by route-model binding under the reserved `/dashboard`
 * prefix, where `dashboard.tenant` binds the authenticated user's Company onto
 * the TenantContext. The global `company_id` scope therefore constrains the
 * binding to the Company's own Events, so a request for an Event belonging to
 * another Company resolves to no model and returns a 404 — no extra guarding is
 * needed here. (Requirement 6.8)
 */
class EventReportController extends Controller
{
    public function __construct(private readonly EventReportService $reports) {}

    /**
     * The per-Event financial report, scoped to the authenticated user's own
     * Company. (Requirements 6.1, 6.2)
     */
    public function show(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        return view('dashboard.events.report', [
            'event' => $event,
            'report' => $this->reports->for($event),
        ]);
    }
}
