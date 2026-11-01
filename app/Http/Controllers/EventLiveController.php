<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\ScanEvent;
use App\Models\Ticket;
use App\Services\Branding\BrandingResolver;
use App\Services\EventReportService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The full-screen "live event" dashboard shown on a display at the door during
 * an Event. (Live event dashboard)
 *
 * Two surfaces:
 *   - {@see show()} renders a chrome-free, black full-screen page: a big
 *     countdown to doors opening before the Event starts, then a 2:1 split —
 *     the left two thirds carry the headline scan stats (a very large
 *     scanned-tickets figure, the % of sold, and the total sold) over a
 *     per-operator bar graph, and the right third is a live feed of every scan
 *     as it happens, including duplicates and errors. A top bar offers "return
 *     to platform" and the company/event logo sits bottom-right. The page never
 *     needs a manual refresh; it polls {@see feed()}.
 *   - {@see feed()} is the lightweight JSON endpoint the page polls on an
 *     interval. It returns the current figures and the most recent scans so the
 *     display updates live without a full reload.
 *
 * The headline counts (scanned / sold / %) come from the shared
 * {@see EventReportService} and {@see Event::sellThrough()} so they never
 * diverge from the reports. The live FEED and the issue tally come from the
 * `scan_events` breadcrumb table, which — unlike `orders.scanned_at` — records
 * every operator's attempts, successes and failures alike.
 *
 * Both are gated on ACTION_VIEW_REPORTS (Owner/Admin/Accountant; Super_Admins
 * bypass) and resolve the Event through the `dashboard.tenant` scope, so an
 * Event belonging to another Company resolves to no model and 404s — no extra
 * guarding is needed. Read-only: no mutation happens here.
 */
class EventLiveController extends Controller
{
    /**
     * How many of the most recent scans the live feed carries. Enough to fill
     * the feed column on a large display without bloating each poll.
     */
    private const FEED_LIMIT = 40;

    public function __construct(
        private readonly EventReportService $reports,
        private readonly BrandingResolver $branding,
    ) {}

    /**
     * Render the full-screen live dashboard. The initial figures are embedded
     * so the page is correct on first paint; thereafter the page polls
     * {@see feed()} to stay live. (Live event dashboard)
     */
    public function show(Request $request, Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        $branding = $this->branding->forEvent($event);

        return view('dashboard.events.live', [
            'event' => $event,
            'branding' => $branding,
            // Doors target: the Event's start time. Null-safe — if unset, the
            // page skips straight to the live feed.
            'doorsAt' => $event->starts_at,
            // Embedded first snapshot so the page renders correct figures
            // before the first poll returns.
            'snapshot' => $this->snapshot($event),
        ]);
    }

    /**
     * The JSON snapshot the live page polls. Returns the headline figures, the
     * per-operator breakdown for the graph, the issue tally, and the most
     * recent scans for the feed. (Live event dashboard)
     */
    public function feed(Request $request, Event $event): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        return response()->json($this->snapshot($event));
    }

    /**
     * Build the live figures for an Event in the exact shape both the embedded
     * first paint and the polled JSON use.
     *
     * @return array{
     *     doorsOpen: bool,
     *     stats: array{scanned:int, sold:int, percent:float|null, checkedInOrders:int, confirmedOrders:int, issues:int},
     *     byOperator: list<array{name:string, tickets:int, orders:int}>,
     *     recent: list<array{id:int, outcome:string, reference:?string, name:?string, operator:string, tickets:int, at:string, atLabel:string, breakdown:string, message:string}>,
     *     generatedAt: string
     * }
     */
    private function snapshot(Event $event): array
    {
        $attendance = $this->reports->attendanceFor($event);

        $sellThrough = $event->sellThrough();
        $sold = (int) $sellThrough['sold'];
        $scanned = $attendance->ticketsCheckedIn;

        // Scanned as a percentage of tickets sold (the headline dial). Null when
        // nothing has sold, so the view can show an em dash rather than 0%.
        $percent = $sold > 0 ? round(min(100, $scanned / $sold * 100), 1) : null;

        // Count of problem scans for this event (duplicates + errors), from the
        // breadcrumb table — the figure behind the "issues" chip.
        $issues = ScanEvent::query()
            ->where('event_id', $event->getKey())
            ->whereIn('outcome', ScanEvent::PROBLEM_OUTCOMES)
            ->count();

        return [
            'doorsOpen' => $event->starts_at === null || now()->gte($event->starts_at),
            'stats' => [
                'scanned' => $scanned,
                'sold' => $sold,
                'percent' => $percent,
                'checkedInOrders' => $attendance->checkedInOrders,
                'confirmedOrders' => $attendance->confirmedOrders,
                'issues' => $issues,
            ],
            'byOperator' => $attendance->byStaff,
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
    private function recentScans(Event $event): array
    {
        /** @var \Illuminate\Support\Collection<int, ScanEvent> $scans */
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
    private function outcomeMessage(string $outcome): string
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
