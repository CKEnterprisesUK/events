<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\ReportController;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Reporting\AttendanceReport;
use App\Services\Reporting\EventReport;
use App\Services\Reporting\ReportDateRange;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single accounting source of truth for a single Event's figures.
 *
 * This service extracts the accounting definitions that previously lived only
 * in {@see ReportController} — the CONFIRMED_STATUSES set,
 * tickets-sold, net-to-company, and gross-revenue — so the company-wide report
 * and the per-event report can never diverge. Every figure here is computed
 * with EXACTLY the same rules the controller uses (Requirement 6.6):
 *
 *   - Confirmed_Order  = status `paid` or `free_confirmed`.
 *   - Tickets_Sold     = count of `valid` tickets on confirmed orders.
 *   - Gross_Revenue    = sum of `order_total_minor` over confirmed orders.
 *   - Net_To_Company   = Gross_Revenue − sum of `application_fee_minor`.
 *   - Stripe_Fees      = sum of `stripe_fee_minor` (actual card-processing fees;
 *                        a not-yet-captured fee counts as 0).
 *   - Net_Payout       = Net_To_Company − Stripe_Fees (the TRUTHFUL amount that
 *                        reaches the connected account's bank).
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
     *                     surfaced by the inline summary (Req 5) and the
     *                     dedicated report page (Req 6).
     */
    public function for(Event $event, ?ReportDateRange $range = null): EventReport
    {
        $range ??= ReportDateRange::none();

        /** @var Collection<int, Order> $confirmed */
        $confirmed = $this->confirmedOrdersQuery($event, $range)->get();

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

        // Gross = collected order totals; Net-to-company = that less the
        // platform's application fee (the direct-charge fee skim). (Req 5.1, 5.3,
        // 6.2, 6.6)
        $grossRevenueMinor = (int) $confirmed->sum('order_total_minor');
        $netToCompanyMinor = $grossRevenueMinor - (int) $confirmed->sum('application_fee_minor');

        // Actual Stripe card-processing fees captured on these confirmed orders.
        // stripe_fee_minor is nullable (unpaid/free orders, or paid orders whose
        // balance transaction has not landed yet), so a null contributes 0 and
        // the figure grows as fees are captured/backfilled. The TRUTHFUL net
        // payout is gross less BOTH the platform fee and this Stripe fee — what
        // actually reaches the connected account's bank. (Truthful-payout)
        $stripeFeesMinor = (int) $confirmed->sum(
            fn (Order $order): int => (int) $order->stripe_fee_minor
        );
        $netPayoutMinor = $netToCompanyMinor - $stripeFeesMinor;

        return new EventReport(
            confirmedOrders: $confirmed->count(),
            ticketsSold: $ticketsSold,
            grossRevenueMinor: $grossRevenueMinor,
            netToCompanyMinor: $netToCompanyMinor,
            stripeFeesMinor: $stripeFeesMinor,
            netPayoutMinor: $netPayoutMinor,
            capacity: $event->capacity,
            perTicketType: $this->perTicketType($event, $confirmedIds),
            ordersByStatus: $this->ordersByStatus($event),
            salesByDay: $this->salesByDay($confirmed, $confirmedIds),
        );
    }

    /**
     * The Event's confirmed Orders query, optionally bounded by an inclusive
     * `from`/`to` range on `created_at`. Tenant-scoped via the Event's relation
     * under the global company scope. Shared by {@see for()} and
     * {@see attendanceFor()} so the financial and attendance reports apply the
     * identical "confirmed within range" definition.
     *
     * @return HasMany<Order, Event>
     */
    private function confirmedOrdersQuery(Event $event, ReportDateRange $range)
    {
        return $event->orders()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->when($range->from !== null, fn ($q) => $q->where('created_at', '>=', $range->from))
            ->when($range->to !== null, fn ($q) => $q->where('created_at', '<=', $range->to));
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
     * A capped type's `remaining` is its per-type ceiling less sold+reserved;
     * a shared-pool type has no per-type ceiling, so its `remaining` is null
     * (the view renders it as '— (shared pool)'). `capacity_mode` carries the
     * type's mode so the view can label it. (Requirements 2.5, 2.6, 6.3)
     *
     * @param  list<int>  $confirmedIds  ids of the Event's confirmed orders.
     * @return list<array{type_id:int,name:string,sold:int,remaining:?int,revenue_minor:int,capacity_mode:string}>
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
                // Per-type remaining is only meaningful for capped types; a
                // shared-pool type has no per-type ceiling, so remaining is
                // null (the view renders it as '— (shared pool)').
                'remaining' => $type->isSharedPool()
                    ? null
                    : (int) $type->capacity - $type->sold_count - $type->reserved_count,
                'revenue_minor' => $sold * $type->price_minor,
                'capacity_mode' => $type->capacity_mode,
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

    /**
     * Produce the check-in / attendance report for a single Event from the
     * `scanned_at` / `scanned_by` stamps the door scanner writes.
     *
     * Attendance is measured against the Event's CONFIRMED orders — the ones
     * that were actually issued a QR and so are eligible for check-in. A
     * confirmed order carrying a `scanned_at` attended; one without is a
     * no-show. Ticket-level headcounts are the count of `valid` tickets on
     * those orders, so the report can express attendance both as orders and as
     * people.
     *
     * All queries are tenant-scoped exactly like {@see for()} (via the Event's
     * `orders()` relation under the global company scope), so the report only
     * ever sees this Company's data.
     */
    public function attendanceFor(Event $event, ?ReportDateRange $range = null): AttendanceReport
    {
        $range ??= ReportDateRange::none();

        /** @var Collection<int, Order> $confirmed */
        $confirmed = $this->confirmedOrdersQuery($event, $range)->get();

        /** @var Collection<int, Order> $checkedIn */
        $checkedIn = $confirmed->whereNotNull('scanned_at')->values();

        // Valid-ticket headcount per confirmed order, so an order can be scored
        // by the number of people it covers, not just as one row.
        $validTicketsByOrder = $this->validTicketsByOrder($confirmed->pluck('id')->all());

        $ticketsSold = (int) $confirmed->sum(
            fn (Order $order): int => (int) $validTicketsByOrder->get($order->id, 0)
        );
        $ticketsCheckedIn = (int) $checkedIn->sum(
            fn (Order $order): int => (int) $validTicketsByOrder->get($order->id, 0)
        );

        $scannedAtValues = $checkedIn->pluck('scanned_at')->filter();

        return new AttendanceReport(
            confirmedOrders: $confirmed->count(),
            checkedInOrders: $checkedIn->count(),
            ticketsSold: $ticketsSold,
            ticketsCheckedIn: $ticketsCheckedIn,
            firstCheckInAt: $scannedAtValues->min()?->toIso8601String(),
            lastCheckInAt: $scannedAtValues->max()?->toIso8601String(),
            byStaff: $this->checkInsByStaff($checkedIn, $validTicketsByOrder),
            timeline: $this->checkInsByHour($checkedIn, $validTicketsByOrder),
        );
    }

    /**
     * Count of `valid` tickets per order id, for the given confirmed orders.
     * Voided tickets (from a later cancel/refund) do not count, mirroring the
     * tickets-sold definition used everywhere else.
     *
     * @param  list<int>  $orderIds
     * @return Collection<int, int> order_id => valid ticket count
     */
    private function validTicketsByOrder(array $orderIds): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        return Ticket::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', Ticket::STATUS_VALID)
            ->get()
            ->groupBy('order_id')
            ->map(fn (Collection $group): int => $group->count());
    }

    /**
     * Check-ins grouped by the staff member who scanned them. `scanned_by`
     * holds the acting user's id; unknown/deleted users (or a null id) fall
     * under "Unknown". Each row carries both the order count and the ticket
     * (headcount) total, ordered by orders scanned, descending.
     *
     * @param  Collection<int, Order>  $checkedIn
     * @param  Collection<int, int>  $validTicketsByOrder
     * @return list<array{name:string,orders:int,tickets:int}>
     */
    private function checkInsByStaff(Collection $checkedIn, Collection $validTicketsByOrder): array
    {
        if ($checkedIn->isEmpty()) {
            return [];
        }

        // Resolve scanner user ids to display names in one query.
        $userIds = $checkedIn->pluck('scanned_by')->filter()->unique()->all();
        $names = $userIds === []
            ? collect()
            : User::query()
                ->whereIn('id', $userIds)
                ->pluck('name', 'id');

        $rows = [];

        foreach ($checkedIn->groupBy('scanned_by') as $scannedBy => $orders) {
            $name = $scannedBy === '' || $scannedBy === null
                ? 'Unknown'
                : (string) ($names->get((int) $scannedBy) ?? 'Unknown');

            $rows[] = [
                'name' => $name,
                'orders' => $orders->count(),
                'tickets' => (int) $orders->sum(
                    fn (Order $order): int => (int) $validTicketsByOrder->get($order->id, 0)
                ),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['orders'] <=> $a['orders']
            ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * Check-ins grouped by clock hour (Y-m-d H:00), so the report can show the
     * arrival curve at the door. Each bucket carries orders and tickets, in
     * chronological order.
     *
     * @param  Collection<int, Order>  $checkedIn
     * @param  Collection<int, int>  $validTicketsByOrder
     * @return list<array{hour:string,orders:int,tickets:int}>
     */
    private function checkInsByHour(Collection $checkedIn, Collection $validTicketsByOrder): array
    {
        if ($checkedIn->isEmpty()) {
            return [];
        }

        $byHour = $checkedIn->groupBy(
            fn (Order $order): string => Carbon::parse($order->scanned_at)->format('Y-m-d H:00')
        );

        $rows = [];

        foreach ($byHour as $hour => $orders) {
            $rows[] = [
                'hour' => (string) $hour,
                'orders' => $orders->count(),
                'tickets' => (int) $orders->sum(
                    fn (Order $order): int => (int) $validTicketsByOrder->get($order->id, 0)
                ),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['hour'], $b['hour']));

        return $rows;
    }
}
