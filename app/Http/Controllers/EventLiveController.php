<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Branding\BrandingResolver;
use App\Services\EventReportService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The full-screen "live event" dashboard shown on a display at the door during
 * an Event. (Live event dashboard)
 *
 * Two surfaces:
 *   - {@see show()} renders a chrome-free, black full-screen page: a countdown
 *     to doors opening before the Event starts, then a 2:1 split — the left two
 *     thirds carry the headline scan stats (total scanned, % of sold, total
 *     sold) over a per-operator bar graph, and the right third is a live feed
 *     of scanned tickets as they come in. A top bar offers "return to platform"
 *     and the company/event logo sits bottom-right. The page never needs a
 *     manual refresh; it polls {@see feed()}.
 *   - {@see feed()} is the lightweight JSON endpoint the page polls on an
 *     interval. It returns the current figures and the most recent check-ins so
 *     the display updates live without a full reload.
 *
 * Both are gated on ACTION_VIEW_REPORTS (Owner/Admin/Accountant; Super_Admins
 * bypass) and resolve the Event through the `dashboard.tenant` scope, so an
 * Event belonging to another Company resolves to no model and 404s — no extra
 * guarding is needed. Read-only: no mutation happens here.
 */
class EventLiveController extends Controller
{
    /**
     * How many of the most recent check-ins the live feed carries. Enough to
     * fill the feed column on a large display without bloating each poll.
     */
    private const FEED_LIMIT = 30;

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
     * per-operator breakdown for the graph, and the most recent check-ins for
     * the feed. (Live event dashboard)
     */
    public function feed(Request $request, Event $event): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        return response()->json($this->snapshot($event));
    }

    /**
     * Build the live figures for an Event in the exact shape both the embedded
     * first paint and the polled JSON use. Scan counts come from the shared
     * {@see EventReportService} (the single attendance source of truth) and the
     * sold total from the same `ticket_types.sold_count` figure used across the
     * app, so these numbers never diverge from the reports.
     *
     * @return array{
     *     doorsOpen: bool,
     *     stats: array{scanned:int, sold:int, percent:float|null, checkedInOrders:int, confirmedOrders:int},
     *     byOperator: list<array{name:string, tickets:int, orders:int}>,
     *     recent: list<array{reference:string, name:string, operator:string, tickets:int, at:string, atLabel:string, breakdown:string}>,
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

        return [
            'doorsOpen' => $event->starts_at === null || now()->gte($event->starts_at),
            'stats' => [
                'scanned' => $scanned,
                'sold' => $sold,
                'percent' => $percent,
                'checkedInOrders' => $attendance->checkedInOrders,
                'confirmedOrders' => $attendance->confirmedOrders,
            ],
            'byOperator' => $attendance->byStaff,
            'recent' => $this->recentScans($event),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * The most recent check-ins for this Event, newest first, with the operator
     * name and a ticket-type breakdown — the live feed rows. Built from the
     * `scanned_at`/`scanned_by` stamps the door scanner writes. Tenant-scoped
     * via the Event relation.
     *
     * @return list<array{reference:string, name:string, operator:string, tickets:int, at:string, atLabel:string, breakdown:string}>
     */
    private function recentScans(Event $event): array
    {
        /** @var \Illuminate\Support\Collection<int, Order> $orders */
        $orders = $event->orders()
            ->whereNotNull('scanned_at')
            ->with('scannedBy:id,name')
            ->orderByDesc('scanned_at')
            ->limit(self::FEED_LIMIT)
            ->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $orderIds = $orders->pluck('id')->all();

        // Valid-ticket count + a ticket-type breakdown per order, in one query
        // each, so the feed shows "how many" and "which types" without N+1.
        $ticketCounts = Ticket::query()
            ->whereIn('order_id', $orderIds)
            ->where('status', Ticket::STATUS_VALID)
            ->selectRaw('order_id, COUNT(*) as qty')
            ->groupBy('order_id')
            ->pluck('qty', 'order_id');

        $breakdowns = DB::table('tickets')
            ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
            ->whereIn('tickets.order_id', $orderIds)
            ->where('tickets.status', Ticket::STATUS_VALID)
            ->selectRaw('tickets.order_id as order_id, ticket_types.name as name, COUNT(*) as qty')
            ->groupBy('tickets.order_id', 'ticket_types.id', 'ticket_types.name')
            ->orderBy('ticket_types.name')
            ->get()
            ->groupBy('order_id');

        return $orders->map(function (Order $order) use ($ticketCounts, $breakdowns): array {
            $rows = $breakdowns->get($order->id, collect());
            $breakdown = $rows
                ->map(fn ($r): string => (int) $r->qty.'× '.$r->name)
                ->implode(', ');

            $scannedAt = Carbon::parse($order->scanned_at);

            return [
                'reference' => (string) $order->order_reference,
                'name' => (string) $order->customer_name,
                'operator' => (string) ($order->scannedBy?->name ?? 'Unknown'),
                'tickets' => (int) ($ticketCounts[$order->id] ?? 0),
                'at' => $scannedAt->toIso8601String(),
                'atLabel' => $scannedAt->format('H:i:s'),
                'breakdown' => $breakdown,
            ];
        })->all();
    }
}
