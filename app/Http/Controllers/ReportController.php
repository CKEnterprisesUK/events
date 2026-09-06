<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
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
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

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
     * omitted. (Requirement 21.1)
     *
     * @param  \Illuminate\Support\Collection<int, Order>  $confirmedOrders
     * @return list<array<string, int|string>>
     */
    private function perEventBreakdown($confirmedOrders): array
    {
        if ($confirmedOrders->isEmpty()) {
            return [];
        }

        $eventNames = Event::query()
            ->whereIn('id', $confirmedOrders->pluck('event_id')->unique()->all())
            ->pluck('name', 'id');

        // Valid ticket counts per Order, so we can aggregate per Event.
        $validTicketsByOrder = Ticket::query()
            ->whereIn('order_id', $confirmedOrders->pluck('id')->all())
            ->where('status', Ticket::STATUS_VALID)
            ->get()
            ->groupBy('order_id')
            ->map(fn ($group) => $group->count());

        $rows = [];

        foreach ($confirmedOrders->groupBy('event_id') as $eventId => $orders) {
            $eventId = (int) $eventId;
            $ticketsSold = (int) $orders->sum(
                fn (Order $order) => $validTicketsByOrder->get($order->id, 0)
            );

            $applicationFees = (int) $orders->sum('application_fee_minor');
            $orderTotal = (int) $orders->sum('order_total_minor');

            $rows[] = [
                'event_id' => $eventId,
                'event_name' => (string) $eventNames->get($eventId, 'Unknown event'),
                'orders' => $orders->count(),
                'gross_sales_minor' => (int) $orders->sum('ticket_subtotal_minor'),
                'booking_fees_minor' => (int) $orders->sum('booking_fee_minor'),
                'application_fees_minor' => $applicationFees,
                'order_total_minor' => $orderTotal,
                'net_to_company_minor' => $orderTotal - $applicationFees,
                'tickets_sold' => $ticketsSold,
            ];
        }

        // Stable, deterministic ordering by event name.
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['event_name'], (string) $b['event_name']));

        return $rows;
    }
}
