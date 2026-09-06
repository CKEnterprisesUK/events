<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Reporting\EventReport;
use Illuminate\Support\Collection;

/**
 * The single accounting source of truth for a single Event's figures.
 *
 * This service extracts the accounting definitions that previously lived only
 * in {@see \App\Http\Controllers\ReportController} — the CONFIRMED_STATUSES set,
 * tickets-sold, net-to-company, and gross-revenue — so the company-wide report
 * and the per-event report can never diverge. Every figure here is computed
 * with EXACTLY the same rules the controller uses (Requirement 6.6):
 *
 *   - Confirmed_Order  = status `paid` or `free_confirmed`.
 *   - Tickets_Sold     = count of `valid` tickets on confirmed orders.
 *   - Gross_Revenue    = sum of `order_total_minor` over confirmed orders.
 *   - Net_To_Company   = Gross_Revenue − sum of `application_fee_minor`.
 *
 * All money is expressed in integer minor-currency units.
 */
class EventReportService
{
    /**
     * The Order statuses whose money and tickets count towards realised
     * revenue: a customer paid, or a free order was confirmed. This mirrors
     * ReportController::CONFIRMED_STATUSES exactly. (Requirement 6.6)
     *
     * @var list<string>
     */
    public const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    /**
     * Produce the full accounting report for a single Event.
     *
     * @return EventReport the immutable value object carrying every figure
     *                      surfaced by the inline summary (Req 5) and the
     *                      dedicated report page (Req 6).
     */
    public function for(Event $event): EventReport
    {
        /** @var Collection<int, Order> $confirmed */
        $confirmed = $event->orders()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->get();

        /** @var list<int> $confirmedIds */
        $confirmedIds = $confirmed->pluck('id')->all();

        // Tickets sold: only `valid` tickets on the confirmed orders count —
        // voided tickets (from a later cancel/refund) do not. (Req 5.2, 6.2)
        $ticketsSold = $confirmedIds === []
            ? 0
            : Ticket::query()
                ->whereIn('order_id', $confirmedIds)
                ->where('status', Ticket::STATUS_VALID)
                ->count();

        // Gross = collected order totals; Net = that less the platform's
        // application fee (the direct-charge fee skim). (Req 5.1, 5.3, 6.2, 6.6)
        $grossRevenueMinor = (int) $confirmed->sum('order_total_minor');
        $netToCompanyMinor = $grossRevenueMinor - (int) $confirmed->sum('application_fee_minor');

        return new EventReport(
            confirmedOrders: $confirmed->count(),
            ticketsSold: $ticketsSold,
            grossRevenueMinor: $grossRevenueMinor,
            netToCompanyMinor: $netToCompanyMinor,
            capacity: $event->capacity,
            perTicketType: $this->perTicketType($event, $confirmedIds),
            ordersByStatus: $this->ordersByStatus($event),
            salesByDay: $this->salesByDay($confirmed, $confirmedIds),
        );
    }

    /**
     * Per-Ticket_Type breakdown: sold, remaining capacity, and revenue for each
     * of the Event's Ticket_Types. (Requirement 6.3)
     *
     * Revenue derivation: a Ticket carries no price of its own — the model only
     * records `order_id`, `ticket_type_id`, and `status`. The price lives on
     * the Ticket_Type (`price_minor`). So a type's revenue is derived as
     * `sold * $type->price_minor`, where `sold` is the count of that type's
     * `valid` tickets on confirmed orders. This is exact because every ticket
     * of a type is priced at that type's `price_minor` at fulfilment.
     *
     * @param  list<int>  $confirmedIds  ids of the Event's confirmed orders.
     * @return list<array{type_id:int,name:string,sold:int,remaining:int,revenue_minor:int}>
     */
    private function perTicketType(Event $event, array $confirmedIds): array
    {
        // Count of `valid` tickets per ticket_type_id across confirmed orders.
        $soldByType = $confirmedIds === []
            ? collect()
            : Ticket::query()
                ->whereIn('order_id', $confirmedIds)
                ->where('status', Ticket::STATUS_VALID)
                ->get()
                ->groupBy('ticket_type_id')
                ->map(fn (Collection $group): int => $group->count());

        $rows = [];

        foreach ($event->ticketTypes as $type) {
            /** @var TicketType $type */
            $sold = (int) $soldByType->get($type->id, 0);

            $rows[] = [
                'type_id' => (int) $type->id,
                'name' => (string) $type->name,
                'sold' => $sold,
                'remaining' => $type->capacity - $type->sold_count - $type->reserved_count,
                'revenue_minor' => $sold * $type->price_minor,
            ];
        }

        return $rows;
    }

    /**
     * Orders-by-status breakdown covering the paid, reserved, refunded,
     * cancelled, and comp order states, keyed by readable label. `comp` maps to
     * the free_confirmed status. Counts are over ALL of the Event's orders
     * (not just confirmed), so reserved/refunded/cancelled are represented.
     * (Requirement 6.4)
     *
     * @return array<string, int>
     */
    private function ordersByStatus(Event $event): array
    {
        // Single grouped query: status => count of the Event's orders.
        $counts = $event->orders()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'paid' => (int) $counts->get(Order::STATUS_PAID, 0),
            'reserved' => (int) $counts->get(Order::STATUS_RESERVED, 0),
            'refunded' => (int) $counts->get(Order::STATUS_REFUNDED, 0),
            'cancelled' => (int) $counts->get(Order::STATUS_CANCELLED, 0),
            'comp' => (int) $counts->get(Order::STATUS_FREE_CONFIRMED, 0),
        ];
    }

    /**
     * Sales-over-time trend: confirmed orders grouped by the day they were
     * fulfilled, each day carrying its tickets-sold and revenue. Where an order
     * has no `fulfilled_at`, its `created_at` is used as a fallback so no
     * confirmed order is dropped from the trend. Returned in chronological day
     * order. (Requirement 6.5)
     *
     * @param  Collection<int, Order>  $confirmed  the Event's confirmed orders.
     * @param  list<int>  $confirmedIds  ids of those confirmed orders.
     * @return list<array{day:string,tickets:int,revenue_minor:int}>
     */
    private function salesByDay(Collection $confirmed, array $confirmedIds): array
    {
        if ($confirmed->isEmpty()) {
            return [];
        }

        // Count of `valid` tickets per order, so each day can be aggregated.
        $validTicketsByOrder = $confirmedIds === []
            ? collect()
            : Ticket::query()
                ->whereIn('order_id', $confirmedIds)
                ->where('status', Ticket::STATUS_VALID)
                ->get()
                ->groupBy('order_id')
                ->map(fn (Collection $group): int => $group->count());

        // Group confirmed orders by their fulfilment day (Y-m-d), falling back
        // to created_at when fulfilled_at is null.
        $byDay = $confirmed->groupBy(function (Order $order): string {
            $moment = $order->fulfilled_at ?? $order->created_at;

            return $moment->format('Y-m-d');
        });

        $rows = [];

        foreach ($byDay as $day => $orders) {
            $tickets = (int) $orders->sum(
                fn (Order $order): int => (int) $validTicketsByOrder->get($order->id, 0)
            );

            $rows[] = [
                'day' => (string) $day,
                'tickets' => $tickets,
                'revenue_minor' => (int) $orders->sum('order_total_minor'),
            ];
        }

        // Chronological ordering by day.
        usort($rows, fn (array $a, array $b): int => strcmp($a['day'], $b['day']));

        return $rows;
    }
}
