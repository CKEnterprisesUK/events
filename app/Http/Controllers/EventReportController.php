<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventReadiness;
use App\Services\EventReportService;
use App\Services\Reporting\ReportDateRange;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-dashboard controller for the per-Event financial report — the
 * dedicated, read-only view of a single Event's sales, revenue, and payout
 * figures. (Requirements 6.1, 6.2)
 *
 * The action is gated by the `ACTION_VIEW_REPORTS` authorisation Gate, which
 * the role matrix grants to the Owner, Admin and Accountant roles (and, via the
 * `Gate::before` bypass, Super_Admins). The Box_Office and Scanner roles are
 * denied with a 403 and see nothing, matching the reporting slice of the
 * permission matrix. (Requirement 6.7)
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
    public function __construct(
        private readonly EventReportService $reports,
        private readonly EventReadiness $readiness,
    ) {}

    /**
     * The per-Event financial report, scoped to the authenticated user's own
     * Company. (Requirements 6.1, 6.2)
     */
    public function show(Request $request, Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        // Optional inclusive from/to range, parsed with the same rules as the
        // company report so every report surface filters consistently.
        $range = ReportDateRange::fromRequest($request);

        return view('dashboard.events.report', [
            'event' => $event,
            'report' => $this->reports->for($event, $range),
            'range' => $range,
            // Supplied so the report renders inside the event-context layout
            // (single sidebar) rather than as a standalone dashboard page.
            'readiness' => $this->readiness->checklist($event),
        ]);
    }

    /**
     * The per-Event attendance / check-in report: how many of the Event's
     * confirmed orders (and the people they cover) were actually scanned in at
     * the door, the no-show gap, who did the scanning, and the arrival curve
     * over time. Built entirely from the `scanned_at`/`scanned_by` stamps the
     * scanner writes, via {@see EventReportService::attendanceFor()}.
     *
     * Same read-only gate, tenant scope and layout as {@see show()}: gated on
     * ACTION_VIEW_REPORTS (Owner/Admin/Accountant), a foreign Event 404s, and it
     * performs no mutation. (Attendance report)
     */
    public function attendance(Request $request, Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        $range = ReportDateRange::fromRequest($request);

        return view('dashboard.events.attendance', [
            'event' => $event,
            'attendance' => $this->reports->attendanceFor($event, $range),
            'range' => $range,
            'readiness' => $this->readiness->checklist($event),
        ]);
    }

    /**
     * Stream the attendance report as a CSV: a headline block (confirmed,
     * checked-in, no-shows, rates), a check-ins-by-staff table, and the hourly
     * arrival timeline. Same gate/scope as {@see attendance()}; read-only. Uses
     * the same streamDownload + fputcsv shape as the other exports so the files
     * stay consistent. (Attendance report)
     */
    public function attendanceExport(Request $request, Event $event): StreamedResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        $range = ReportDateRange::fromRequest($request);
        $report = $this->reports->attendanceFor($event, $range);

        $slug = Str::slug($event->name) ?: 'event';
        $filename = 'attendance-'.$slug.'-'.now()->format('Y-m-d').'.csv';

        $pct = fn (?float $v): string => $v === null ? '—' : number_format($v, 1).'%';

        return response()->streamDownload(function () use ($report, $pct): void {
            $out = fopen('php://output', 'wb');

            // Headline figures.
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['Confirmed orders', $report->confirmedOrders]);
            fputcsv($out, ['Checked-in orders', $report->checkedInOrders]);
            fputcsv($out, ['No-show orders', $report->noShowOrders()]);
            fputcsv($out, ['Attendance rate (orders)', $pct($report->attendanceRate())]);
            fputcsv($out, ['No-show rate (orders)', $pct($report->noShowRate())]);
            fputcsv($out, ['Tickets sold', $report->ticketsSold]);
            fputcsv($out, ['Tickets checked in', $report->ticketsCheckedIn]);
            fputcsv($out, ['Attendance rate (headcount)', $pct($report->headcountRate())]);

            // Check-ins by staff.
            fputcsv($out, []);
            fputcsv($out, ['Checked in by', 'Orders', 'Tickets']);
            foreach ($report->byStaff as $row) {
                fputcsv($out, [$row['name'], $row['orders'], $row['tickets']]);
            }

            // Hourly arrival timeline.
            fputcsv($out, []);
            fputcsv($out, ['Hour', 'Orders', 'Tickets']);
            foreach ($report->timeline as $row) {
                fputcsv($out, [$row['hour'], $row['orders'], $row['tickets']]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
