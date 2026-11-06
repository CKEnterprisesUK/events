<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\EventReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Extra analysis for the Reports & Analytics page, layered on top of the
 * confirmed-Orders set the Company report already uses: the ticket-type
 * breakdown, previous-period comparison and the refunds summary.
 *
 * Everything here is built from the SAME "confirmed order" definition as
 * {@see EventReportService::CONFIRMED_STATUSES}, and ticket-type
 * revenue is derived as sold × `price_minor` exactly as the per-Event report
 * does, so these figures can never disagree with the headline totals.
 *
 * Money stays in integer minor units. All queries are tenant-scoped through the
 * global `company_id` scope on the underlying models.
 */
class ReportInsights
{
    /**
     * Per-Ticket_Type performance for the given confirmed Orders.
     *
     * `sold` is the number of valid tickets on those Orders (i.e. within the
     * chosen filters). `sell_through_percent` and `remaining` describe the
     * Ticket_Type's lifetime allocation, so they only exist for capped types.
     * When a single Event is selected its Ticket_Types with no sales are listed
     * too, so unsold allocations are visible.
     *
     * @param  Collection<int, Order>  $confirmedOrders
     * @return list<array{type_id:int,type_name:string,event_name:string,price_minor:int,sold:int,revenue_minor:int,share_percent:float,capacity:?int,remaining:?int,sell_through_percent:?float}>
     */
    public function ticketTypeBreakdown(Collection $confirmedOrders, ?int $eventId = null): array
    {
        $orderIds = $confirmedOrders->pluck('id')->all();

        /** @var Collection<int|string, int> $soldByType */
        $soldByType = $orderIds === []
            ? collect()
            : Ticket::query()
                ->whereIn('order_id', $orderIds)
                ->where('status', Ticket::STATUS_VALID)
                ->selectRaw('ticket_type_id, COUNT(*) as aggregate')
                ->groupBy('ticket_type_id')
                ->pluck('aggregate', 'ticket_type_id');

        $types = TicketType::query()
            ->with('event:id,name')
            ->where(function ($query) use ($soldByType, $eventId): void {
                $query->whereIn('id', $soldByType->keys()->all());

                if ($eventId !== null) {
                    $query->orWhere('event_id', $eventId);
                }
            })
            ->get();

        $totalRevenueMinor = $types->sum(
            fn (TicketType $type): int => (int) $soldByType->get($type->id, 0) * (int) $type->price_minor
        );

        $rows = $types->map(function (TicketType $type) use ($soldByType, $totalRevenueMinor): array {
            $sold = (int) $soldByType->get($type->id, 0);
            $revenue = $sold * (int) $type->price_minor;
            $isCapped = $type->isCapped() && (int) $type->capacity > 0;
            $committed = (int) $type->sold_count + (int) $type->reserved_count;

            return [
                'type_id' => (int) $type->id,
                'type_name' => (string) $type->name,
                'event_name' => (string) ($type->event?->name ?? 'Unknown event'),
                'price_minor' => (int) $type->price_minor,
                'sold' => $sold,
                'revenue_minor' => $revenue,
                'share_percent' => $totalRevenueMinor > 0 ? round($revenue / $totalRevenueMinor * 100, 1) : 0.0,
                'capacity' => $isCapped ? (int) $type->capacity : null,
                'remaining' => $isCapped ? max(0, (int) $type->capacity - $committed) : null,
                'sell_through_percent' => $isCapped ? round($committed / (int) $type->capacity * 100, 1) : null,
            ];
        })->all();

        usort($rows, fn (array $a, array $b): int => [$b['revenue_minor'], $b['sold'], $a['type_name']]
            <=> [$a['revenue_minor'], $a['sold'], $b['type_name']]);

        return $rows;
    }

    /**
     * The equally long period immediately before `$from`–`$to`, or null when the
     * range is open-ended (there is nothing meaningful to compare against).
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function previousPeriod(?Carbon $from, ?Carbon $to): ?array
    {
        if ($from === null || $to === null) {
            return null;
        }

        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;

        return [
            $from->copy()->subDays($days)->startOfDay(),
            $from->copy()->subDay()->endOfDay(),
        ];
    }

    /**
     * Percentage change from `$previous` to `$current`, or null when there is no
     * previous value to compare against (avoids a misleading "+∞%").
     */
    public function percentChange(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /**
     * Gross sales per day for the inclusive `$from`–`$to` window, zero-filled.
     * Used to overlay the previous period on the current one, day for day.
     *
     * @param  Collection<int, Order>  $confirmedOrders
     * @return list<int>
     */
    public function dailyGross(Collection $confirmedOrders, Carbon $from, Carbon $to): array
    {
        $byDay = [];

        foreach ($confirmedOrders as $order) {
            if ($order->created_at === null) {
                continue;
            }

            $key = $order->created_at->toDateString();
            $byDay[$key] = ($byDay[$key] ?? 0) + (int) $order->ticket_subtotal_minor;
        }

        $values = [];

        for ($day = $from->copy()->startOfDay(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $values[] = $byDay[$day->toDateString()] ?? 0;
        }

        return $values;
    }

    /**
     * Refunds issued on Orders in the period: how many Orders carry a refund and
     * the cumulative amount refunded. Counted over ALL statuses, because partial
     * refunds leave an Order confirmed while full refunds move it out of the
     * confirmed set. Informational only: it does not change the reconciliation.
     *
     * @return array{orders:int, amount_minor:int}
     */
    public function refunds(?Carbon $from, ?Carbon $to, ?int $eventId): array
    {
        $orders = Order::query()
            ->where('refunded_total_minor', '>', 0)
            ->when($from !== null, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn ($q) => $q->where('created_at', '<=', $to))
            ->when($eventId !== null, fn ($q) => $q->where('event_id', $eventId))
            ->get(['id', 'refunded_total_minor']);

        return [
            'orders' => $orders->count(),
            'amount_minor' => (int) $orders->sum('refunded_total_minor'),
        ];
    }

    /**
     * The Company's Events that are candidates for the Attendance tab, newest
     * first, honouring the single-event filter.
     *
     * @return Collection<int, Event>
     */
    public function attendanceEvents(?int $eventId): Collection
    {
        return Event::query()
            ->when($eventId !== null, fn ($q) => $q->where('id', $eventId))
            ->orderByDesc('id')
            ->limit(25)
            ->get(['id', 'name', 'starts_at']);
    }
}
