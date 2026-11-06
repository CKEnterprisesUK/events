<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for ticket counts on the organiser dashboard.
 *
 * Every figure is derived from the `tickets` table (joined to its order), never
 * from the denormalised `ticket_types.sold_count` counter, so the headline KPI
 * and the per-event rows can not disagree. The queries are explicitly scoped to
 * a company because the dashboard home runs outside the tenant middleware.
 */
class TicketMetrics
{
    /**
     * @return array<int, TicketCounts> keyed by event id
     */
    public function forEvents(int $companyId, ?array $eventIds = null): array
    {
        if ($eventIds !== null && $eventIds === []) {
            return [];
        }

        $rows = $this->baseQuery($companyId)
            ->when($eventIds !== null, fn ($q) => $q->whereIn('orders.event_id', $eventIds))
            ->selectRaw('orders.event_id as event_id')
            ->groupBy('orders.event_id')
            ->get();

        $byEvent = [];
        foreach ($rows as $row) {
            $byEvent[(int) $row->event_id] = $this->hydrate($row);
        }

        return $byEvent;
    }

    public function forCompany(int $companyId): TicketCounts
    {
        $row = $this->baseQuery($companyId)->first();

        return $row === null ? new TicketCounts : $this->hydrate($row);
    }

    public function forEvent(int $companyId, int $eventId): TicketCounts
    {
        return $this->forEvents($companyId, [$eventId])[$eventId] ?? new TicketCounts;
    }

    private function baseQuery(int $companyId): Builder
    {
        $confirmed = "'".Order::STATUS_PAID."','".Order::STATUS_FREE_CONFIRMED."'";
        $valid = "'".Ticket::STATUS_VALID."'";
        $voided = "'".Ticket::STATUS_VOIDED."'";
        $refunded = "'".Order::STATUS_REFUNDED."'";

        return DB::table('tickets')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('orders.company_id', $companyId)
            ->selectRaw('COUNT(*) as issued')
            ->selectRaw("COALESCE(SUM(CASE WHEN tickets.status = {$valid} AND orders.status IN ({$confirmed}) THEN 1 ELSE 0 END), 0) as sold")
            ->selectRaw("COALESCE(SUM(CASE WHEN tickets.status = {$voided} THEN 1 ELSE 0 END), 0) as voided")
            ->selectRaw("COALESCE(SUM(CASE WHEN tickets.status = {$voided} AND orders.status = {$refunded} THEN 1 ELSE 0 END), 0) as refunded")
            ->selectRaw("COALESCE(SUM(CASE WHEN tickets.status = {$valid} AND orders.status IN ({$confirmed}) AND orders.scanned_at IS NOT NULL THEN 1 ELSE 0 END), 0) as checked_in");
    }

    private function hydrate(object $row): TicketCounts
    {
        return new TicketCounts(
            issued: (int) $row->issued,
            sold: (int) $row->sold,
            voided: (int) $row->voided,
            refunded: (int) $row->refunded,
            checkedIn: (int) $row->checked_in,
        );
    }
}
