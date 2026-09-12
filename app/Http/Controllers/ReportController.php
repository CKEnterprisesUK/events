<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\EventReportService;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
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

    public function __construct(
        private readonly EventReportService $reports,
        private readonly TenantContext $tenantContext,
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
        $confirmedOrders = $this->confirmedOrders($from, $to);

        return view('dashboard.reports.index', [
            'totals' => $this->companyTotals($confirmedOrders),
            'perEvent' => $this->perEventBreakdown($confirmedOrders, $ranged),
            'currency' => $this->currency(),
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
        ]);
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
        $confirmedOrders = $this->confirmedOrders($from, $to);
        $totals = $this->companyTotals($confirmedOrders);
        $perEvent = $this->perEventBreakdown($confirmedOrders, $ranged);
        $currency = $this->currency();
        $rangeLabel = $this->rangeLabel($from, $to);

        $filename = 'sales-report-'.$this->filenameRange($from, $to).'.csv';

        return response()->streamDownload(function () use ($totals, $perEvent, $currency, $rangeLabel): void {
            $out = fopen('php://output', 'wb');

            // Period the figures cover, so the exported file is self-describing.
            fputcsv($out, ['Period', $rangeLabel]);
            fputcsv($out, []);

            // Company totals block. Money columns are written in major units.
            fputcsv($out, ['Company totals', 'Value ('.$currency.')']);
            fputcsv($out, ['Confirmed orders', $totals['orders']]);
            fputcsv($out, ['Tickets sold', $totals['tickets_sold']]);
            fputcsv($out, ['Gross sales', $this->major($totals['gross_sales_minor'])]);
            fputcsv($out, ['Booking fees collected', $this->major($totals['booking_fees_minor'])]);
            fputcsv($out, ['Platform fees', $this->major($totals['application_fees_minor'])]);
            fputcsv($out, ['Total collected', $this->major($totals['order_total_minor'])]);
            fputcsv($out, ['Net after platform fee', $this->major($totals['net_to_company_minor'])]);
            fputcsv($out, ['Stripe processing fees', $this->major($totals['stripe_fees_minor'])]);
            fputcsv($out, ['Net payout to bank', $this->major($totals['net_payout_minor'])]);

            // Blank separator, then the per-Event breakdown table.
            fputcsv($out, []);
            fputcsv($out, [
                'Event', 'Orders', 'Tickets sold', 'Gross sales', 'Booking fees',
                'Platform fees', 'Total collected', 'Net after platform fee',
                'Stripe fees', 'Net payout',
            ]);

            foreach ($perEvent as $row) {
                fputcsv($out, [
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
        $confirmedOrders = $this->confirmedOrders($from, $to);

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
    private function confirmedOrders(?Carbon $from = null, ?Carbon $to = null)
    {
        return Order::query()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
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
