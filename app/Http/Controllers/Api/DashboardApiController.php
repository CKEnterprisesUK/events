<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\EventLiveController;
use App\Models\Event;
use App\Services\EventSnapshotService;
use App\Services\RoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The native iOS scanner's live door dashboard — the JSON twin of the web
 * {@see EventLiveController} full-screen display, for the
 * operator standing at the door. (Requirements 7.1–7.5)
 *
 * Like the web dashboard it leans on the shared {@see EventSnapshotService} so
 * the figures can never diverge, but it differs in two deliberate ways noted in
 * the design:
 *
 *   - Gating is `ACTION_CHECK_IN`, NOT the web's `ACTION_VIEW_REPORTS`. The API
 *     dashboard is a door tool for the person scanning, so the Scanner role may
 *     see it; a role without check-in is denied 403 with no data read
 *     (Requirements 7.1, 7.5).
 *   - The payload OMITS all sales/revenue framing (Requirement 7.3). It carries
 *     only attendance counts — scanned, sold, remaining (= sold − scanned), and
 *     rate (scanned as a % of sold) — plus the recent `ScanEvent` feed. No gross
 *     revenue, net payout, or per-type money figure is ever included.
 *
 * There is no `{event}` in the path: the dashboard resolves the Active_Event
 * itself — the Active_Company's most imminent/in-progress published,
 * non-cancelled Event (its `starts_at` nearest now) — tenant-scoped via the
 * global `company_id` scope bound by `api.tenant`, so only this Company's
 * Events are ever candidates (Requirements 7.2, 7.4). When the Company has no
 * such Event, the response is a `null` event with zeroed stats and an empty
 * feed rather than a 404, so the client can render an idle state.
 */
class DashboardApiController extends Controller
{
    public function __construct(private readonly EventSnapshotService $snapshots) {}

    /**
     * The live snapshot for the Active_Event: attendance counts and the recent
     * scan feed, with no sales/revenue figures. (Requirements 7.1–7.5)
     */
    public function live(Request $request): JsonResponse
    {
        // 7.1 / 7.5 — the check-in Gate runs before anything is read, so a role
        // without check_in is denied 403 and no Event is ever resolved. This is
        // the one deliberate authorisation difference from the web dashboard,
        // which gates on ACTION_VIEW_REPORTS.
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        // The operator may pin the dashboard to a specific Event they chose on
        // the Events tab (`?event={id}`); it is resolved tenant-scoped, so a
        // foreign/unknown id simply falls through to the auto-resolved
        // Active_Event. With no param we keep the prior behaviour of showing the
        // Company's most imminent/in-progress Event.
        $requestedId = (int) $request->query('event', 0);

        $event = $requestedId > 0
            ? (Event::query()->whereKey($requestedId)->first() ?? $this->activeEvent())
            : $this->activeEvent();

        if ($event === null) {
            return response()->json([
                'event' => null,
                'stats' => ['scanned' => 0, 'sold' => 0, 'remaining' => 0, 'rate' => null],
                'recent' => [],
                'generatedAt' => now()->toIso8601String(),
            ]);
        }

        $snapshot = $this->snapshots->snapshot($event);

        $scanned = (int) $snapshot['stats']['scanned'];
        $sold = (int) $snapshot['stats']['sold'];

        return response()->json([
            'event' => [
                'id' => (int) $event->getKey(),
                'name' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ],
            // Attendance only — NO sales/revenue figures (7.3). `remaining` is
            // tickets sold but not yet scanned (sold − scanned); `rate` is the
            // scanned-of-sold percentage the shared service already computes.
            'stats' => [
                'scanned' => $scanned,
                'sold' => $sold,
                'remaining' => max(0, $sold - $scanned),
                'rate' => $snapshot['stats']['percent'],
            ],
            'recent' => $this->strip($snapshot['recent']),
            'generatedAt' => $snapshot['generatedAt'],
        ]);
    }

    /**
     * Resolve the Active_Event for the bound Active_Company: the most
     * imminent/in-progress published, non-cancelled Event — the one whose
     * `starts_at` is nearest now. Tenant-scoped via the global `company_id`
     * scope, so only this Company's Events are candidates (Requirements 7.2,
     * 7.4). An Event in progress (started a little ago) and the next upcoming
     * one both sort to the front; an Event that has long finished loses to a
     * nearer one. Returns null when the Company has no eligible Event.
     */
    private function activeEvent(): ?Event
    {
        return Event::query()
            ->where('is_published', true)
            ->whereNull('cancelled_at')
            ->whereNotNull('starts_at')
            ->orderByRaw('ABS(TIMESTAMPDIFF(SECOND, starts_at, ?))', [now()])
            ->first();
    }

    /**
     * Reduce the shared feed rows to the Scan_Outcome fields the API contract
     * exposes (outcome, operator, reference, customer, tickets, at), dropping
     * the web-only presentation helpers (atLabel, breakdown string, message).
     *
     * @param  list<array<string, mixed>>  $recent
     * @return list<array{outcome:string, operator:string, reference:?string, customer:?string, tickets:int, at:string}>
     */
    private function strip(array $recent): array
    {
        return array_map(fn (array $row): array => [
            'outcome' => (string) $row['outcome'],
            'operator' => (string) $row['operator'],
            'reference' => $row['reference'],
            'customer' => $row['name'],
            'tickets' => (int) $row['tickets'],
            'at' => (string) $row['at'],
        ], $recent);
    }
}
