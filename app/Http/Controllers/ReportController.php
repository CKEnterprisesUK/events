<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\EventReportService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

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

    public function __construct(private readonly EventReportService $reports) {}

    /**
     * The Company's sales/revenue report plus payout information, scoped to the
     * Accountant's own Company. (Requirements 21.1, 21.3)
     */
    public function index(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        $confirmedOrders = Order::query()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->get();

        // Company-wide totals in integer minor units. Net to company is what
        // settles to the connected account: the collected Order_Total less the
        // Platform's Application_Fee (the direct-charge fee skim).
        $grossSalesMinor = (int) $confirmedOrders->sum('ticket_subtotal_minor');
        $bookingFeesMinor = (int) $confirmedOrders->sum('booking_fee_minor');
        $applicationFeesMinor = (int) $confirmedOrders->sum('application_fee_minor');
        $orderTotalMinor = (int) $confirmedOrders->sum('order_total_minor');
        $netToCompanyMinor = $orderTotalMinor - $applicationFeesMinor;

        $confirmedOrderIds = $confirmedOrders->pluck('id')->all();

        // Tickets sold: valid tickets on the confirmed Orders. Voided tickets
        // (from a later cancel/refund) do not count towards realised sales.
        $ticketsSold = $confirmedOrderIds === []
            ? 0
            : Ticket::query()
                ->whereIn('order_id', $confirmedOrderIds)
                ->where('status', Ticket::STATUS_VALID)
                ->count();

        $totals = [
            'orders' => $confirmedOrders->count(),
            'gross_sales_minor' => $grossSalesMinor,
            'booking_fees_minor' => $bookingFeesMinor,
            'application_fees_minor' => $applicationFeesMinor,
            'order_total_minor' => $orderTotalMinor,
            'net_to_company_minor' => $netToCompanyMinor,
            'tickets_sold' => $ticketsSold,
        ];

        $perEvent = $this->perEventBreakdown($confirmedOrders);

        return view('dashboard.reports.index', [
            'totals' => $totals,
            'perEvent' => $perEvent,
        ]);
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
     * @param  \Illuminate\Support\Collection<int, Order>  $confirmedOrders
     * @return list<array<string, int|string>>
     */
    private function perEventBreakdown($confirmedOrders): array
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

            // Delegate to the single accounting source of truth for the shared
            // figures. The service re-queries the same confirmed Orders under
            // the active tenant scope, so its numbers match the ones this
            // report groups by Event. (Requirement 6.6)
            $report = $this->reports->for($event);

            $rows[] = [
                'event_id' => $eventId,
                'event_name' => (string) ($event->name ?? 'Unknown event'),
                'orders' => $report->confirmedOrders,
                // Service does not own these two columns — sum locally.
                'gross_sales_minor' => (int) $orders->sum('ticket_subtotal_minor'),
                'booking_fees_minor' => (int) $orders->sum('booking_fee_minor'),
                // Recover the platform fee from the service's own net definition
                // (net = order_total − application_fee) to keep it derived from
                // the shared source rather than recomputed.
                'application_fees_minor' => $report->grossRevenueMinor - $report->netToCompanyMinor,
                'order_total_minor' => $report->grossRevenueMinor,
                'net_to_company_minor' => $report->netToCompanyMinor,
                'tickets_sold' => $report->ticketsSold,
            ];
        }

        // Stable, deterministic ordering by event name.
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['event_name'], (string) $b['event_name']));

        return $rows;
    }
}
