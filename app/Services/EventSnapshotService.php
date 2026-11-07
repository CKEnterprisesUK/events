<?php

namespace App\Services;

use App\Http\Controllers\Api\DashboardApiController;
use App\Http\Controllers\EventLiveController;
use App\Models\Event;
use App\Models\ScanEvent;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Builds the live figures for an Event's door dashboard — the shared source of
 * truth behind BOTH the web {@see EventLiveController}
 * full-screen display and the native iOS
 * {@see DashboardApiController} snapshot.
 *
 * The logic was extracted verbatim from `EventLiveController::snapshot()` so the
 * web surface is unchanged: {@see snapshot()} returns the exact same shape the
 * controller used to assemble inline (doors-open flag, headline stats,
 * per-operator breakdown, recent feed, generated-at).
 *
 * The headline counts (scanned / sold / %) come from
 * {@see EventReportService::liveAttendanceFor()} — the aggregate-query twin of
 * {@see EventReportService::attendanceFor()}, using the same definition (valid
 * tickets on confirmed orders) — so they never diverge from the attendance
 * report and sold is always consistent with scanned. The assembled snapshot is
 * cached for `api.snapshot_ttl` seconds so many viewers share one build. The
 * live FEED comes from the `scan_events`
 * breadcrumb table, which — unlike `orders.scanned_at` — records every
 * operator's attempts, successes AND failures (duplicates, rejections, invalid
 * codes), so rejected tickets show in the feed too.
 *
 * Everything runs under the active tenant scope (the global `company_id` scope
 * on {@see ScanEvent} and the Event's own relations), so an Event belonging to
 * another Company never leaks.
 */
class EventSnapshotService
{
    /**
     * How many of the most recent scans the live feed carries. Enough to fill
     * the feed column on a large display without bloating each poll.
     */
    public const FEED_LIMIT = 40;

    public function __construct(private readonly EventReportService $reports) {}

    /**
     * Build the live figures for an Event in the exact shape both the web
     * embedded first paint and the polled JSON use.
     *
     * @return array{
     *     doorsOpen: bool,
     *     stats: array{scanned:int, sold:int, capacity:int|null, percent:float|null, checkedInOrders:int, confirmedOrders:int},
     *     byOperator: list<array{name:string, tickets:int, orders:int}>,
     *     recent: list<array{id:int, outcome:string, reference:?string, name:?string, operator:string, tickets:int, at:string, atLabel:string, breakdown:string, message:string}>,
     *     generatedAt: string
     * }
     */
    public function snapshot(Event $event): array
    {
        $ttl = (int) config('api.snapshot_ttl', 0);

        if ($ttl <= 0) {
            return $this->buildSnapshot($event);
        }

        // Every open door display and scanner dashboard polls this every couple
        // of seconds, and each poll costs several queries. A very short shared
        // cache means N viewers cost one build per TTL window instead of N, at
        // the price of figures being at most `$ttl` seconds old. Keyed by
        // Company AND Event so it can never cross tenants.
        $key = sprintf('event-snapshot:%d:%d', $event->company_id, $event->getKey());

        return Cache::remember($key, $ttl, fn (): array => $this->buildSnapshot($event));
    }

    /**
     * Assemble the snapshot from the database, uncached.
     *
     * @return array{
     *     doorsOpen: bool,
     *     stats: array{scanned:int, sold:int, capacity:int|null, percent:float|null, checkedInOrders:int, confirmedOrders:int},
     *     byOperator: list<array{name:string, tickets:int, orders:int}>,
     *     recent: list<array{id:int, outcome:string, reference:?string, name:?string, operator:string, tickets:int, at:string, atLabel:string, breakdown:string, message:string}>,
     *     generatedAt: string
     * }
     */
    private function buildSnapshot(Event $event): array
    {
        $attendance = $this->reports->liveAttendanceFor($event);

        // Both figures come from the SAME authoritative definition — valid
        // Ticket rows on confirmed orders, the one the attendance report uses —
        // so "sold" and "scanned" are always consistent (sold ≥ scanned). We
        // deliberately do NOT read Event::sellThrough() here: that uses the
        // denormalised ticket_types.sold_count counter, which can read 0 for
        // orders inserted directly (e.g. pre-prod seed data) even though real
        // tickets exist.
        $sold = $attendance['ticketsSold'];
        $scanned = $attendance['ticketsCheckedIn'];

        // Scanned as a percentage of tickets sold (the headline dial). Null when
        // nothing has sold, so the view can show an em dash rather than 0%.
        $percent = $sold > 0 ? round(min(100, $scanned / $sold * 100), 1) : null;

        return [
            'doorsOpen' => $event->starts_at === null || now()->gte($event->starts_at),
            'stats' => [
                'scanned' => $scanned,
                'sold' => $sold,
                // Ceiling for sales (null = unlimited); drives "tickets left".
                'capacity' => $event->sellThrough()['capacity'],
                'percent' => $percent,
                'checkedInOrders' => $attendance['checkedInOrders'],
                'confirmedOrders' => $attendance['confirmedOrders'],
            ],
            'byOperator' => $attendance['byStaff'],
            'recent' => $this->recentScans($event),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * The most recent scans for this Event, newest first — successes,
     * duplicates and errors alike — from the `scan_events` breadcrumb table.
     * Each row carries the operator, outcome and (for scans that matched an
     * Order) a ticket-type breakdown. Tenant-scoped via the global company
     * scope on {@see ScanEvent}; `event_id` narrows to this Event.
     *
     * @return list<array{id:int, outcome:string, reference:?string, name:?string, operator:string, tickets:int, at:string, atLabel:string, breakdown:string, message:string}>
     */
    public function recentScans(Event $event): array
    {
        /** @var Collection<int, ScanEvent> $scans */
        $scans = ScanEvent::query()
            ->where('event_id', $event->getKey())
            ->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get();

        if ($scans->isEmpty()) {
            return [];
        }

        // One query for a ticket-type breakdown across all the orders the feed
        // references, so each successful/duplicate row can show which types the
        // order covers without an N+1.
        $orderIds = $scans->pluck('order_id')->filter()->unique()->all();

        $breakdowns = $orderIds === []
            ? collect()
            : DB::table('tickets')
                ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
                ->whereIn('tickets.order_id', $orderIds)
                ->where('tickets.status', Ticket::STATUS_VALID)
                ->selectRaw('tickets.order_id as order_id, ticket_types.name as name, COUNT(*) as qty')
                ->groupBy('tickets.order_id', 'ticket_types.id', 'ticket_types.name')
                ->orderBy('ticket_types.name')
                ->get()
                ->groupBy('order_id');

        return $scans->map(function (ScanEvent $scan) use ($breakdowns): array {
            $rows = $scan->order_id ? $breakdowns->get($scan->order_id, collect()) : collect();
            $breakdown = $rows
                ->map(fn ($r): string => (int) $r->qty.'× '.$r->name)
                ->implode(', ');

            $at = $scan->created_at ?? now();

            return [
                'id' => (int) $scan->id,
                'outcome' => (string) $scan->outcome,
                'reference' => $scan->reference,
                'name' => $scan->customer_name,
                'operator' => (string) ($scan->operator_name ?? 'Unknown'),
                'tickets' => (int) $scan->tickets,
                'at' => $at->toIso8601String(),
                'atLabel' => $at->format('H:i:s'),
                'breakdown' => $breakdown,
                'message' => $this->outcomeMessage($scan->outcome),
            ];
        })->all();
    }

    /**
     * A short human label for a scan outcome, shown on error/duplicate feed
     * rows where there is no customer context to lean on.
     */
    public function outcomeMessage(string $outcome): string
    {
        return match ($outcome) {
            ScanEvent::OUTCOME_SUCCESS => 'Checked in',
            ScanEvent::OUTCOME_DUPLICATE => 'Already scanned',
            ScanEvent::OUTCOME_REJECTED => 'Wrong event / organisation',
            ScanEvent::OUTCOME_FAILED => 'Ticket no longer valid',
            ScanEvent::OUTCOME_UNREADABLE => 'Unreadable code',
            default => 'Invalid code',
        };
    }
}
