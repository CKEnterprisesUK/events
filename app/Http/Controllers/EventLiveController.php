<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\Branding\BrandingResolver;
use App\Services\EventSnapshotService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * Both surfaces delegate the figures to the shared
 * {@see EventSnapshotService}, which the API dashboard also uses so the two can
 * never diverge. The headline counts (scanned / sold / %) come from valid
 * tickets on confirmed orders, so they match the attendance report and sold is
 * always consistent with scanned. The live FEED comes from the `scan_events`
 * breadcrumb table, which — unlike `orders.scanned_at` — records every
 * operator's attempts, successes AND failures (duplicates, rejections, invalid
 * codes), so rejected tickets show in the feed too.
 *
 * Both are gated on ACTION_VIEW_REPORTS (Owner/Admin/Accountant; Super_Admins
 * bypass) and resolve the Event through the `dashboard.tenant` scope, so an
 * Event belonging to another Company resolves to no model and 404s — no extra
 * guarding is needed. Read-only: no mutation happens here.
 */
class EventLiveController extends Controller
{
    public function __construct(
        private readonly EventSnapshotService $snapshots,
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
            'snapshot' => $this->snapshots->snapshot($event),
        ]);
    }

    /**
     * The JSON snapshot the live page polls. Returns the headline figures, the
     * per-operator breakdown for the graph, and the most recent scans for the
     * feed. (Live event dashboard)
     */
    public function feed(Request $request, Event $event): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS);

        return response()->json($this->snapshots->snapshot($event));
    }
}
