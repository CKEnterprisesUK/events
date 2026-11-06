<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\EventComparisonService;
use App\Services\EventReportService;
use App\Services\Reporting\ReportInsights;
use App\Services\RoleAuthorization;
use App\Services\SimpleXlsxWriter;
use App\Services\TenantContext;
use App\Support\Csv;
use App\Support\PlatformBrand;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-dashboard controller for the Accountant's read-only reports and
 * payout information. (Requirement 21)
 *
 * Every action is gated by the `ACTION_VIEW_REPORTS` authorisation Gate, which
 * the role matrix grants only to the Accountant role (and, via the
 * `Gate::before` bypass, Super_Admins). Any other Company role — Owner, Admin,
 * Scanner — is denied with a 403 and sees nothing, matching the read-only
 * reporting slice of the permission matrix. (Requirements 3.5, 21.1, Property 6)
 *
 * This controller is deliberately READ-ONLY: it exposes only GET endpoints and
 * performs no mutations. The Accountant can view figures but can never modify
 * an Event, Ticket_Type, or Order — those write actions live on the Admin
 * gates, so an Accountant hitting them is denied with an authorisation error
 * leaving all data unchanged. (Requirements 21.2, 3.7)
 *
 * All figures are scoped to the Accountant's own Company: the dashboard runs
 * under the reserved `/dashboard` prefix where `dashboard.tenant` binds the
 * authenticated user's Company onto the TenantContext, so the global
 * `company_id` scope constrains every Order/Ticket/Event query to that Company.
 * An Accountant therefore only ever sees their own Company's finances.
 * (Requirements 21.3, 1.5)
 *
 * Money framing (design: Stripe Connect Standard, direct charges): funds settle
 * directly to the Company's connected account, and the Platform skims its cut
 * via `application_fee_amount`. So the Application_Fee is CK's platform fee and
 * the net that reaches the Company is the Order_Total minus that Application_Fee
 * — this is the "payout" figure surfaced here. All money is in integer minor
 * currency units throughout. (Requirements 12.1, 12.2, 13.4)
 */
class ReportController extends Controller
{
    /**
     * The Order statuses whose money and tickets count towards the Company's
     * realised revenue: a Customer paid, or a free Order was confirmed. Reserved
     * holds, expired/cancelled/voided Orders, and refunded/disputed Orders are
     * excluded — they represent no settled revenue to the Company. (13.7, 17.x)
     *
     * This mirrors {@see EventReportService::CONFIRMED_STATUSES} exactly. The
     * per-Event breakdown delegates to {@see EventReportService} (the single
     * accounting source of truth) so the company-wide totals here and the
     * per-Event figures can never diverge. (Requirement 6.6)
     *
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = EventReportService::CONFIRMED_STATUSES;

    /**
     * The report sections, in tab order. `sales` is the default.
     *
     * @var list<string>
     */
    private const TABS = ['sales', 'tickets', 'finance', 'attendance'];

    /**
     * @var array<string, string>
     */
    private const TAB_LABELS = [
        'sales' => 'Sales & Revenue',
        'tickets' => 'Ticket Performance',
        'finance' => 'Finance & Payouts',
        'attendance' => 'Attendance',
    ];

    public function __construct(
        private readonly EventReportService $reports,
        private readonly TenantContext $tenantContext,
        private readonly ReportInsights $insights,
    ) {}

    /**
     * The currency of the Company currently being acted on.
     *
     * Reads the tenant bound onto {@see TenantContext} by `dashboard.tenant` —
     * the user's own Company OR, for an impersonating Super_Admin, the Company
     * they have jumped into — so the reported figures carry the impersonated
     * Company's currency rather than the admin's own. Falls back to the user's
     * own Company, then GBP.
     */
    private function currency(): string
    {
        $company = $this->tenantContext->company() ?? Auth::user()?->company;

        return (string) ($company?->currency ?? 'GBP');
    }

    /**
     * The Company's sales/revenue report plus payout information, scoped to the
     * Accountant's own Company. An optional `from`/`to` date range (inclusive,
     * on the Order's `created_at` day) narrows the figures so an Accountant can
     * pull a specific month/quarter for reconciliation; with no range the report
     * covers all realised sales as before. (Requirements 21.1, 21.3)
     */
    public function index(Request $request): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        [$from, $to] = $this->dateRange($request);
        $ranged = $from !== null || $to !== null;

        // Optional single-event filter: narrow every figure on the page to one
        // Event. An absent/unknown id shows the whole Company as before.
        $eventId = $request->query('event');
        $eventId = is_numeric($eventId) ? (int) $eventId : null;

        $tab = $request->query('tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : self::TABS[0];

        $confirmedOrders = $this->confirmedOrders($from, $to, $eventId);
        $totals = $this->companyTotals($confirmedOrders);

        $data = [
            'tab' => $tab,
            'tabs' => self::TAB_LABELS,
            'totals' => $totals,
            'perEvent' => $this->perEventBreakdown($confirmedOrders, $ranged),
            'salesTimeline' => $this->salesTimeline($confirmedOrders),
            'currency' => $this->currency(),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'events' => $this->companyEvents(),
            'eventOptions' => $this->eventOptions(),
            'eventId' => $eventId,
            'averageOrderMinor' => $totals['orders'] > 0
                ? intdiv($totals['gross_sales_minor'], $totals['orders'])
                : 0,
            'comparison' => null,
            'ticketTypes' => [],
            'refunds' => null,
            'attendanceEvents' => collect(),
        ];

        // Only the active tab's extra analysis is computed.
        if ($tab === 'sales') {
            $data['comparison'] = $this->previousPeriodComparison($from, $to, $eventId, $totals);
        } elseif ($tab === 'tickets') {
            $data['ticketTypes'] = $this->insights->ticketTypeBreakdown($confirmedOrders, $eventId);
        } elseif ($tab === 'finance') {
            $data['refunds'] = $this->insights->refunds($from, $to, $eventId);
        } elseif ($tab === 'attendance') {
            $data['attendanceEvents'] = $this->insights->attendanceEvents($eventId);
        }

        return view('dashboard.reports.index', $data);
    }

    /**
     * Compare the current period with the equally long one before it: headline
     * deltas plus a day-for-day gross-sales overlay. Null for an open-ended
     * range, where there is no meaningful previous period.
     *
     * @param  array<string, int>  $totals
     * @return array{label:string, deltas:array<string, array{previous:int, change:?float}>, series:list<array{label:string, value:int}>, previous_series:list<array{label:string, value:int}>}|null
     */
    private function previousPeriodComparison(?Carbon $from, ?Carbon $to, ?int $eventId, array $totals): ?array
    {
        $period = $this->insights->previousPeriod($from, $to);

        if ($period === null) {
            return null;
        }

        [$previousFrom, $previousTo] = $period;

        $previousOrders = $this->confirmedOrders($previousFrom, $previousTo, $eventId);
        $previousTotals = $this->companyTotals($previousOrders);

        $deltas = [];
        foreach (['gross_sales_minor', 'orders', 'tickets_sold', 'net_payout_minor'] as $metric) {
            $deltas[$metric] = [
                'previous' => $previousTotals[$metric],
                'change' => $this->insights->percentChange($totals[$metric], $previousTotals[$metric]),
            ];
        }

        $current = $this->insights->dailyGross($this->confirmedOrders($from, $to, $eventId), $from, $to);
        $previous = $this->insights->dailyGross($previousOrders, $previousFrom, $previousTo);

        $series = [];
        $previousSeries = [];
        foreach ($current as $index => $value) {
            $label = $from->copy()->addDays($index)->format('j M');
            $series[] = ['label' => $label, 'value' => $value];
            $previousSeries[] = ['label' => $label, 'value' => $previous[$index] ?? 0];
        }

        return [
            'label' => $this->rangeLabel($previousFrom, $previousTo),
            'deltas' => $deltas,
            'series' => $series,
            'previous_series' => $previousSeries,
        ];
    }

    /**
     * A daily gross-sales timeline over the covered period, for the reports
     * page's trend chart: one zero-filled point per calendar day from the first
     * to the last confirmed order in the set, each the sum of
     * `ticket_subtotal_minor` (gross sales) placed that day. Built from the SAME
     * confirmed-Orders collection the tables use, so the chart agrees with the
     * figures beside it. Returns an empty list when there are no sales to plot.
     *
     * The series is capped at a sensible number of days so an all-time report
     * doesn't render thousands of points: when the span exceeds the cap it is
     * aggregated by week instead of by day.
     *
     * @param  Collection<int, Order>  $confirmedOrders
     * @return list<array{label:string, value:int}>
     */
    private function salesTimeline($confirmedOrders): array
    {
        $dated = $confirmedOrders->filter(fn (Order $o) => $o->created_at !== null);

        if ($dated->isEmpty()) {
            return [];
        }

        $start = $dated->min(fn (Order $o) => $o->created_at)->copy()->startOfDay();
        $end = $dated->max(fn (Order $o) => $o->created_at)->copy()->startOfDay();

        // Decide day vs. week granularity by the span so the chart stays legible.
        $spanDays = (int) $start->diffInDays($end) + 1;
        $byWeek = $spanDays > 120;

        // Sum gross sales into day or ISO-week buckets.
        $buckets = [];
        foreach ($dated as $order) {
            $key = $byWeek
                ? $order->created_at->copy()->startOfWeek()->toDateString()
                : $order->created_at->copy()->startOfDay()->toDateString();
            $buckets[$key] = ($buckets[$key] ?? 0) + (int) $order->ticket_subtotal_minor;
        }

        // Zero-fill across the whole span so gaps read as "no sales", not
        // missing data, and the x-axis is evenly spaced.
        $points = [];
        $cursor = $byWeek ? $start->copy()->startOfWeek() : $start->copy();
        $stepDays = $byWeek ? 7 : 1;

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $cursor->toDateString();
            $points[] = [
                'label' => $cursor->format('j M'),
                'value' => $buckets[$key] ?? 0,
            ];
            $cursor->addDays($stepDays);
        }

        return $points;
    }

    /**
     * Compare two (or more) of the Company's Events side by side: how sales
     * built up in the lead-up to each Event (on a shared "days before the
     * event" axis so differently-dated Events align), and how sales are spread
     * across the hours of the day. Gated on `ACTION_VIEW_REPORTS` and scoped to
     * the acting Company — foreign Events simply don't resolve. Read-only.
     *
     * Events are chosen via repeatable `events[]` query ids (2–3). With fewer
     * than two valid Events the page renders the picker and a prompt rather than
     * an empty chart.
     */
    public function compare(Request $request, EventComparisonService $comparison): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        // Resolve up to three requested events, tenant-scoped, preserving the
        // requested order so the first pick is always the first series.
        $requestedIds = collect((array) $request->query('events', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->take(3)
            ->values();

        $events = $requestedIds->isEmpty()
            ? collect()
            : Event::query()
                ->whereIn('id', $requestedIds->all())
                ->get()
                ->sortBy(fn (Event $e) => $requestedIds->search($e->getKey()))
                ->values();

        return view('dashboard.reports.compare', [
            'events' => $this->companyEvents(),
            'eventOptions' => $this->eventOptions(),
            'selected' => $events,
            'selectedIds' => $events->pluck('id')->all(),
            'comparison' => $events->count() >= 2 ? $comparison->compare($events) : null,
            'currency' => $this->currency(),
        ]);
    }

    /**
     * Dropdown labels for the event pickers: the event name plus where it is in
     * its life (Cancelled, Draft, Upcoming or Past) and its date, so an
     * organiser can tell at a glance whether an event has already happened.
     * Newest first, keyed id => label.
     *
     * @return Collection<int, string>
     */
    private function eventOptions()
    {
        return Event::query()
            ->orderByDesc('id')
            ->get(['id', 'name', 'starts_at', 'is_published', 'cancelled_at'])
            ->mapWithKeys(function (Event $event): array {
                $state = match (true) {
                    $event->cancelled_at !== null => 'Cancelled',
                    ! $event->is_published => 'Draft',
                    $event->starts_at !== null && $event->starts_at->isPast() => 'Past',
                    default => 'Upcoming',
                };
                $date = $event->starts_at?->format('j M Y');

                return [$event->id => $event->name.' ('.$state.($date ? ', '.$date : '').')'];
            });
    }

    /**
     * The Company's Events for the report filter and comparison pickers, newest
     * first and keyed id => name. Tenant-scoped via the global company scope.
     *
     * @return Collection<int, string>
     */
    private function companyEvents()
    {
        return Event::query()
            ->orderByDesc('id')
            ->pluck('name', 'id');
    }

    /**
     * Stream the same company report as a CSV download: a company-totals block
     * followed by the per-Event breakdown, money rendered in major units to two
     * decimal places (e.g. 125.00) so the file opens cleanly in a spreadsheet.
     *
     * Same gate and tenant scope as {@see index()} — the Accountant/Owner only
     * ever exports their own Company's realised figures. Read-only: it performs
     * no mutation. (Requirements 21.1, 21.3)
     */
    public function export(Request $request): StreamedResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        [$from, $to] = $this->dateRange($request);
        $ranged = $from !== null || $to !== null;
        $confirmedOrders = $this->confirmedOrders($from, $to, $this->eventIdFrom($request));
        $totals = $this->companyTotals($confirmedOrders);
        $perEvent = $this->perEventBreakdown($confirmedOrders, $ranged);
        $currency = $this->currency();
        $rangeLabel = $this->rangeLabel($from, $to);

        $filename = 'sales-report-'.$this->filenameRange($from, $to).'.csv';

        return response()->streamDownload(function () use ($totals, $perEvent, $currency, $rangeLabel): void {
            $out = fopen('php://output', 'wb');

            // Period the figures cover, so the exported file is self-describing.
            Csv::put($out, ['Period', $rangeLabel]);
            Csv::put($out, []);

            // Company totals block. Money columns are written in major units.
            Csv::put($out, ['Company totals', 'Value ('.$currency.')']);
            Csv::put($out, ['Confirmed orders', $totals['orders']]);
            Csv::put($out, ['Tickets sold', $totals['tickets_sold']]);
            Csv::put($out, ['Gross sales', $this->major($totals['gross_sales_minor'])]);
            Csv::put($out, ['Booking fees collected', $this->major($totals['booking_fees_minor'])]);
            Csv::put($out, ['Platform fees', $this->major($totals['application_fees_minor'])]);
            Csv::put($out, ['Total collected', $this->major($totals['order_total_minor'])]);
            Csv::put($out, ['Net after platform fee', $this->major($totals['net_to_company_minor'])]);
            Csv::put($out, ['Stripe processing fees', $this->major($totals['stripe_fees_minor'])]);
            Csv::put($out, ['Net payout to bank', $this->major($totals['net_payout_minor'])]);

            // Blank separator, then the per-Event breakdown table.
            Csv::put($out, []);
            Csv::put($out, [
                'Event', 'Orders', 'Tickets sold', 'Gross sales', 'Booking fees',
                'Platform fees', 'Total collected', 'Net after platform fee',
                'Stripe fees', 'Net payout',
            ]);

            foreach ($perEvent as $row) {
                Csv::put($out, [
                    $row['event_name'],
                    $row['orders'],
                    $row['tickets_sold'],
                    $this->major($row['gross_sales_minor']),
                    $this->major($row['booking_fees_minor']),
                    $this->major($row['application_fees_minor']),
                    $this->major($row['order_total_minor']),
                    $this->major($row['net_to_company_minor']),
                    $this->major($row['stripe_fees_minor']),
                    $this->major($row['net_payout_minor']),
                ]);
            }

            // Platform attribution footer.
            Csv::put($out, []);
            Csv::put($out, [PlatformBrand::line()]);

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Render the same company report as a formatted PDF payout statement —
     * something an Accountant can file or hand to a bookkeeper, with the full
     * fee/net-payout reconciliation and per-Event breakdown. Same gate, tenant
     * scope, figures and optional `from`/`to` range as {@see index()} and
     * {@see export()}, built from the identical confirmed-Orders query so the
     * three surfaces never diverge. Read-only. (Requirements 21.1, 21.3)
     */
    public function exportPdf(Request $request): Response
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        [$from, $to] = $this->dateRange($request);
        $ranged = $from !== null || $to !== null;
        $confirmedOrders = $this->confirmedOrders($from, $to, $this->eventIdFrom($request));

        $company = $this->tenantContext->company() ?? Auth::user()?->company;

        $pdf = Pdf::loadView('dashboard.reports.pdf', [
            'totals' => $this->companyTotals($confirmedOrders),
            'perEvent' => $this->perEventBreakdown($confirmedOrders, $ranged),
            'currency' => $this->currency(),
            'companyName' => (string) ($company?->name ?? 'Your organisation'),
            'rangeLabel' => $this->rangeLabel($from, $to),
            'generatedAt' => now()->format('j M Y, H:i'),
        ]);

        return $pdf->download('sales-report-'.$this->filenameRange($from, $to).'.pdf');
    }

    /**
     * Export the same company report as a real `.xlsx` workbook — the format a
     * finance team can open natively in Excel and total/pivot, unlike the CSV.
     * Built from the identical confirmed-Orders query, gate, tenant scope and
     * optional `from`/`to` range as {@see index()}, {@see export()} and
     * {@see exportPdf()}, so all four surfaces agree. Money is written in major
     * units as real numbers so the spreadsheet can sum them. Read-only.
     *
     * The workbook is assembled in memory by {@see SimpleXlsxWriter} (no new
     * dependency; uses PHP's Zip extension). (Report export types)
     */
    public function exportExcel(Request $request, SimpleXlsxWriter $writer): Response
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        [$from, $to] = $this->dateRange($request);
        $ranged = $from !== null || $to !== null;
        $confirmedOrders = $this->confirmedOrders($from, $to, $this->eventIdFrom($request));
        $totals = $this->companyTotals($confirmedOrders);
        $perEvent = $this->perEventBreakdown($confirmedOrders, $ranged);
        $currency = $this->currency();

        // Period the figures cover, so the file is self-describing.
        $writer->addRow(['Period', $this->rangeLabel($from, $to)]);
        $writer->addBlankRow();

        // Company totals block. Money columns are real numbers in major units.
        $writer->addRow(['Company totals', 'Value ('.$currency.')']);
        $writer->addRow(['Confirmed orders', (int) $totals['orders']]);
        $writer->addRow(['Tickets sold', (int) $totals['tickets_sold']]);
        $writer->addRow(['Gross sales', $this->majorFloat($totals['gross_sales_minor'])]);
        $writer->addRow(['Booking fees collected', $this->majorFloat($totals['booking_fees_minor'])]);
        $writer->addRow(['Platform fees', $this->majorFloat($totals['application_fees_minor'])]);
        $writer->addRow(['Total collected', $this->majorFloat($totals['order_total_minor'])]);
        $writer->addRow(['Net after platform fee', $this->majorFloat($totals['net_to_company_minor'])]);
        $writer->addRow(['Stripe processing fees', $this->majorFloat($totals['stripe_fees_minor'])]);
        $writer->addRow(['Net payout to bank', $this->majorFloat($totals['net_payout_minor'])]);

        // Blank separator, then the per-Event breakdown table.
        $writer->addBlankRow();
        $writer->addRow([
            'Event', 'Orders', 'Tickets sold', 'Gross sales', 'Booking fees',
            'Platform fees', 'Total collected', 'Net after platform fee',
            'Stripe fees', 'Net payout',
        ]);

        foreach ($perEvent as $row) {
            $writer->addRow([
                (string) $row['event_name'],
                (int) $row['orders'],
                (int) $row['tickets_sold'],
                $this->majorFloat($row['gross_sales_minor']),
                $this->majorFloat($row['booking_fees_minor']),
                $this->majorFloat($row['application_fees_minor']),
                $this->majorFloat($row['order_total_minor']),
                $this->majorFloat($row['net_to_company_minor']),
                $this->majorFloat($row['stripe_fees_minor']),
                $this->majorFloat($row['net_payout_minor']),
            ]);
        }

        // Platform attribution footer.
        $writer->addBlankRow();
        $writer->addRow([PlatformBrand::line()]);

        $filename = 'sales-report-'.$this->filenameRange($from, $to).'.xlsx';

        return response($writer->build(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename='.$filename,
        ]);
    }

    /**
     * Resolve an optional inclusive `from`/`to` date range from the request.
     * Each is a `Y-m-d` date; an invalid or absent value becomes null (no
     * bound). If both are present and reversed, they are swapped so the range is
     * always well-ordered. Returned as start-of-day `from` and end-of-day `to`
     * Carbon instances (or null) ready to bound `created_at`.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function dateRange(Request $request): array
    {
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            $from?->startOfDay(),
            $to?->endOfDay(),
        ];
    }

    /**
     * Parse a `Y-m-d` query value into a Carbon date, or null when absent or
     * malformed (so a bad param simply widens the range rather than erroring).
     */
    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A human-readable label for the covered period, for report headers/exports.
     */
    private function rangeLabel(?Carbon $from, ?Carbon $to): string
    {
        if ($from === null && $to === null) {
            return 'All time';
        }

        $fmt = fn (?Carbon $d): string => $d?->format('j M Y') ?? '…';

        if ($from !== null && $to === null) {
            return 'From '.$fmt($from);
        }

        if ($from === null && $to !== null) {
            return 'Up to '.$fmt($to);
        }

        return $fmt($from).' – '.$fmt($to);
    }

    /**
     * A filename-safe slug for the covered period, e.g. `2026-01-01_to_2026-01-31`
     * or the current date when the range is open (all time).
     */
    private function filenameRange(?Carbon $from, ?Carbon $to): string
    {
        if ($from === null && $to === null) {
            return now()->format('Y-m-d');
        }

        return ($from?->format('Y-m-d') ?? 'start').'_to_'.($to?->format('Y-m-d') ?? now()->format('Y-m-d'));
    }

    /**
     * The Company's confirmed Orders — the single query the HTML report, the CSV
     * export and the PDF export all build their figures from, kept identical so
     * the surfaces can never diverge. Tenant-scoped via the global company
     * scope. An optional inclusive `from`/`to` range bounds the Order
     * `created_at`. (Requirements 21.1, 21.3)
     *
     * @return Collection<int, Order>
     */
    /**
     * The optional single-event filter id from the request, or null when absent
     * or non-numeric (so a bad param simply widens to the whole Company).
     */
    private function eventIdFrom(Request $request): ?int
    {
        $eventId = $request->query('event');

        return is_numeric($eventId) ? (int) $eventId : null;
    }

    private function confirmedOrders(?Carbon $from = null, ?Carbon $to = null, ?int $eventId = null)
    {
        return Order::query()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($eventId !== null, fn ($q) => $q->where('event_id', $eventId))
            ->get();
    }

    /**
     * Company-wide totals in integer minor units. Net to company is what
     * settles to the connected account: the collected Order_Total less the
     * Platform's Application_Fee (the direct-charge fee skim).
     *
     * The `net_to_company_minor` is net of the platform fee only; the truthful
     * `net_payout_minor` also subtracts the actual `stripe_fees_minor`.
     *
     * @param  Collection<int, Order>  $confirmedOrders
     * @return array<string, int>
     */
    private function companyTotals($confirmedOrders): array
    {
        $applicationFeesMinor = (int) $confirmedOrders->sum('application_fee_minor');
        $orderTotalMinor = (int) $confirmedOrders->sum('order_total_minor');

        $confirmedOrderIds = $confirmedOrders->pluck('id')->all();

        // Tickets sold: valid tickets on the confirmed Orders. Voided tickets
        // (from a later cancel/refund) do not count towards realised sales.
        $ticketsSold = $confirmedOrderIds === []
            ? 0
            : Ticket::query()
                ->whereIn('order_id', $confirmedOrderIds)
                ->where('status', Ticket::STATUS_VALID)
                ->count();

        // Actual Stripe card-processing fees captured on the confirmed Orders.
        // stripe_fee_minor is nullable (not yet captured), so a null counts as 0
        // and the figure fills in as fees land/are backfilled. The TRUTHFUL
        // payout subtracts BOTH the platform fee and this Stripe fee from the
        // collected total — what actually reaches the Company's bank, unlike the
        // platform-fee-only "net to company" figure above. (Truthful-payout)
        $stripeFeesMinor = (int) $confirmedOrders->sum(
            fn (Order $order): int => (int) $order->stripe_fee_minor
        );
        $netToCompanyMinor = $orderTotalMinor - $applicationFeesMinor;

        return [
            'orders' => $confirmedOrders->count(),
            'gross_sales_minor' => (int) $confirmedOrders->sum('ticket_subtotal_minor'),
            'booking_fees_minor' => (int) $confirmedOrders->sum('booking_fee_minor'),
            'application_fees_minor' => $applicationFeesMinor,
            'order_total_minor' => $orderTotalMinor,
            'net_to_company_minor' => $netToCompanyMinor,
            'stripe_fees_minor' => $stripeFeesMinor,
            'net_payout_minor' => $netToCompanyMinor - $stripeFeesMinor,
            'tickets_sold' => $ticketsSold,
        ];
    }

    /**
     * Render integer minor currency units as a major-unit string with two
     * decimal places (e.g. 12500 => "125.00") for the CSV, without a currency
     * symbol so the value stays numeric in a spreadsheet.
     */
    private function major(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }

    /**
     * Render integer minor currency units as a major-unit float (e.g. 12500 =>
     * 125.0) for the Excel export, where the value should land in a numeric
     * cell the spreadsheet can total — unlike {@see major()}, which returns a
     * fixed-precision string for the CSV.
     */
    private function majorFloat(int $minor): float
    {
        return round($minor / 100, 2);
    }

    /**
     * A per-Event breakdown of the same figures, computed from the confirmed
     * Orders grouped by their Event. Events with no confirmed Orders are
     * omitted. (Requirements 21.1, 6.6)
     *
     * The shared accounting figures — confirmed order count, tickets sold,
     * order total, net to company, and (by derivation) the application fee —
     * are taken from {@see EventReportService}, the single source of truth,
     * rather than recomputed here, so the per-Event breakdown can never diverge
     * from the dedicated per-Event report page. The two money columns the
     * company report needs but the service does not own — `gross_sales_minor`
     * (sum of `ticket_subtotal_minor`) and `booking_fees_minor` (sum of
     * `booking_fee_minor`) — are still summed locally from the confirmed Orders
     * this method already holds. The service's `grossRevenueMinor` is the
     * collected `order_total_minor` (NOT the ticket subtotal), so it maps onto
     * this row's `order_total_minor`, and `application_fees_minor` is recovered
     * as `order_total − net`, which is the service's own definition of net.
     *
     * When a date range is active (`$ranged`), the shared figures are computed
     * from the already-range-filtered `$confirmedOrders` group directly, using
     * the SAME definitions the service applies, so the per-Event rows respect the
     * range and still sum to the company totals. With no range they are taken
     * from {@see EventReportService} unchanged, preserving the parity guarantee
     * (Property 12) that the whole-history per-Event report and the company
     * report agree. Both paths yield the identical row shape.
     *
     * @param  Collection<int, Order>  $confirmedOrders
     * @return list<array<string, int|string>>
     */
    private function perEventBreakdown($confirmedOrders, bool $ranged = false): array
    {
        if ($confirmedOrders->isEmpty()) {
            return [];
        }

        $events = Event::query()
            ->whereIn('id', $confirmedOrders->pluck('event_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($confirmedOrders->groupBy('event_id') as $eventId => $orders) {
            $eventId = (int) $eventId;
            $event = $events->get($eventId);

            $rows[] = $ranged
                ? $this->rangedEventRow($eventId, $event, $orders)
                : $this->wholeHistoryEventRow($eventId, $event, $orders);
        }

        // Stable, deterministic ordering by event name.
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['event_name'], (string) $b['event_name']));

        return $rows;
    }

    /**
     * A per-Event row for the whole-history report: shared accounting figures
     * are taken from {@see EventReportService} (the single source of truth) so
     * the per-Event and company reports can never diverge. (Requirement 6.6)
     *
     * @param  Collection<int, Order>  $orders  this Event's confirmed Orders.
     * @return array<string, int|string>
     */
    private function wholeHistoryEventRow(int $eventId, ?Event $event, $orders): array
    {
        // The service re-queries this Event's confirmed Orders under the active
        // tenant scope, so its numbers match the ones grouped here.
        $report = $this->reports->for($event);

        return [
            'event_id' => $eventId,
            'event_name' => (string) ($event->name ?? 'Unknown event'),
            'orders' => $report->confirmedOrders,
            // Service does not own these two columns — sum locally.
            'gross_sales_minor' => (int) $orders->sum('ticket_subtotal_minor'),
            'booking_fees_minor' => (int) $orders->sum('booking_fee_minor'),
            // Recover the platform fee from the service's own net definition
            // (net = order_total − application_fee) to keep it derived from the
            // shared source rather than recomputed.
            'application_fees_minor' => $report->grossRevenueMinor - $report->netToCompanyMinor,
            'order_total_minor' => $report->grossRevenueMinor,
            'net_to_company_minor' => $report->netToCompanyMinor,
            'stripe_fees_minor' => $report->stripeFeesMinor,
            'net_payout_minor' => $report->netPayoutMinor,
            'tickets_sold' => $report->ticketsSold,
        ];
    }

    /**
     * A per-Event row for a DATE-RANGED report: every figure is derived from the
     * already-range-filtered `$orders` for this Event, applying the same
     * accounting definitions the service uses (gross = Σ order_total; net-to-
     * company = gross − Σ application_fee; Stripe fees = Σ stripe_fee; net payout
     * = net-to-company − Stripe fees; tickets sold = valid tickets on these
     * Orders). This keeps the per-Event rows consistent with the ranged company
     * totals, which are summed from the same filtered collection.
     *
     * @param  Collection<int, Order>  $orders  this Event's range-filtered confirmed Orders.
     * @return array<string, int|string>
     */
    private function rangedEventRow(int $eventId, ?Event $event, $orders): array
    {
        $orderIds = $orders->pluck('id')->all();

        $ticketsSold = $orderIds === []
            ? 0
            : Ticket::query()
                ->whereIn('order_id', $orderIds)
                ->where('status', Ticket::STATUS_VALID)
                ->count();

        $orderTotalMinor = (int) $orders->sum('order_total_minor');
        $applicationFeesMinor = (int) $orders->sum('application_fee_minor');
        $stripeFeesMinor = (int) $orders->sum(fn (Order $o): int => (int) $o->stripe_fee_minor);
        $netToCompanyMinor = $orderTotalMinor - $applicationFeesMinor;

        return [
            'event_id' => $eventId,
            'event_name' => (string) ($event->name ?? 'Unknown event'),
            'orders' => $orders->count(),
            'gross_sales_minor' => (int) $orders->sum('ticket_subtotal_minor'),
            'booking_fees_minor' => (int) $orders->sum('booking_fee_minor'),
            'application_fees_minor' => $applicationFeesMinor,
            'order_total_minor' => $orderTotalMinor,
            'net_to_company_minor' => $netToCompanyMinor,
            'stripe_fees_minor' => $stripeFeesMinor,
            'net_payout_minor' => $netToCompanyMinor - $stripeFeesMinor,
            'tickets_sold' => $ticketsSold,
        ];
    }
}
