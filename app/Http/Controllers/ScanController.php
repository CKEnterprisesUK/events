<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Operator;
use App\Services\RoleAuthorization;
use App\Services\ScanResult;
use App\Services\TicketCheckInService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * The phone-browser QR scanner and its check-in endpoint. (Requirement 16.x)
 *
 * The scanner surface is gated on the Scanner role's `ACTION_CHECK_IN` Gate
 * (Requirements 3.6, 3.7) — Owners/Admins/Accountants and any user whose role
 * does not permit check-in are denied with a 403 leaving all data unchanged.
 * (Super_Admins bypass the Company matrix via the global `Gate::before` hook.)
 *
 * The scanner page ({@see index}) is a plain HTML page that opens the device
 * camera in the browser (via `getUserMedia` + a client-side QR decoder) and
 * POSTs the decoded payload back to {@see scan}. No app install is required
 * (Requirement 16.1); when the browser denies camera permission the page shows
 * a "camera access required" message and performs no scan (Requirement 16.2).
 *
 * {@see scan} is a thin adapter over the shared {@see TicketCheckInService}:
 * it builds an {@see Operator} from the authenticated user, hands the decoded
 * payload to the service — which decodes + HMAC-verifies it, loads the Order
 * through the tenant scope, enforces the confirmed/already-scanned checks and
 * performs the atomic single check-in, and records the Scan_Event — then maps
 * the returned {@see ScanResult} back onto this controller's session recap and
 * rendered HTML fragment. The decision tree itself (Requirements 16.3–16.10 /
 * 5.3–5.10) lives in the service so the web and iOS/JSON surfaces can never
 * diverge on what each outcome means.
 */
class ScanController extends Controller
{
    /**
     * The session key under which we keep a short rolling list of the most
     * recent scans for this operator. This is intentionally session-scoped
     * (per user, per browser) and capped — it is a convenience recap for the
     * person on the door, not an audit trail (the AuditLog is authoritative),
     * so it needs no database table and clears with the session.
     */
    private const HISTORY_KEY = 'scan.history';

    /**
     * How many recent scans to remember. Small on purpose so the recap stays
     * glanceable and the session payload stays tiny.
     */
    private const HISTORY_LIMIT = 5;

    /**
     * The Order_Reference of the single most recent scan this operator made in
     * this browser session. Used to catch an accidental immediate re-scan of
     * the exact ticket just scanned (a quick double-tap/double-pass of the same
     * QR) and warn the operator distinctly — only ever the last one ticket, so
     * scanning any other ticket in between clears it. Session-scoped (per
     * operator, per browser), like the recap; it is a UX guard, not an audit
     * trail.
     */
    private const LAST_REFERENCE_KEY = 'scan.last_reference';

    public function __construct(private TicketCheckInService $checkIn) {}

    /**
     * The scanner start page (intermediary): a calm landing that offers a
     * "start scanning" action and shows the recent-scan recap, without opening
     * the camera. Keeping the camera off this page means the live scanner
     * ({@see live}) can drop its heading/help text and give the result banner
     * the whole screen. (Requirements 16.1, 16.2)
     */
    public function index(Request $request): View
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        return view('dashboard.scan.start', [
            'history' => $this->history($request),
        ]);
    }

    /**
     * The live scanner page: opens the camera in the browser and POSTs decoded
     * payloads to {@see scan}. This is the minimal, chrome-free surface reached
     * from the start page. (Requirements 16.1, 16.2)
     */
    public function live(Request $request): View
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        return view('dashboard.scan.index', [
            'history' => $this->history($request),
        ]);
    }

    /**
     * Verify a decoded QR payload and, when valid and unscanned, atomically
     * check the Order in. Delegates the decision tree and the Scan_Event
     * recording to {@see TicketCheckInService}, then records the outcome in the
     * session recap and renders the live scanner page with a structured
     * `result` describing the outcome. (Requirements 16.3–16.10)
     */
    public function scan(Request $request): View|JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $payload = (string) $request->input('payload', '');

        // Hand the decoded payload to the shared service on behalf of the
        // authenticated operator. The service verifies the code, performs the
        // atomic check-in and records the Scan_Event; we map its structured
        // ScanResult back onto the view-facing array this controller renders.
        $result = $this->toArray(
            $this->checkIn->resolve($payload, Operator::fromUser(Auth::user()))
        );

        // Catch an accidental immediate re-scan: if this resolved to a known
        // Order whose reference matches the single most recent scan this
        // operator made, flag it so the banner warns "you've just scanned this
        // ticket" rather than the generic already-scanned message. Only the
        // last one ticket counts — scanning any other ticket in between resets
        // the guard. We compute this BEFORE recording the new reference so the
        // comparison is against the prior scan, not this one.
        $this->flagJustScanned($request, $result);

        $this->remember($request, $result);

        $history = $this->history($request);

        // The live scanner POSTs in the background (fetch) so the camera keeps
        // running: it wants just the pieces it swaps in — the result banner it
        // shows as a dismissible overlay, and the refreshed recent-scan list —
        // not a whole new page. A plain (no-JS) POST still gets the full page,
        // so the scanner degrades gracefully when JavaScript is unavailable.
        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'status' => $result['status'],
                'resultHtml' => view('dashboard.scan._result', [
                    'result' => $result,
                ])->render(),
                'historyHtml' => view('dashboard.scan._history', [
                    'history' => $history,
                ])->render(),
            ]);
        }

        return view('dashboard.scan.index', [
            'result' => $result,
            'history' => $history,
        ]);
    }

    /**
     * Flatten a {@see ScanResult} into the view-facing array this controller
     * has always rendered. The keys and shape are identical to the array the
     * old private `resolve()` returned, so `dashboard.scan._result`, the recap
     * and {@see remember()} read exactly the same fields as before — only
     * populated keys are included so `isset()`/`empty()` checks still fire.
     *
     * @return array{status: string, message: string, order?: Order, scanned_at?: Carbon, scanned_by_name?: ?string, breakdown?: array<int, array{name: string, quantity: int}>}
     */
    private function toArray(ScanResult $result): array
    {
        $array = [
            'status' => $result->status,
            'message' => $result->message,
        ];

        if ($result->order !== null) {
            $array['order'] = $result->order;
        }

        if ($result->scannedAt !== null) {
            $array['scanned_at'] = $result->scannedAt;
        }

        // already_scanned carries the scanning operator's name (null when the
        // account no longer exists); keep the key present as the prior result
        // did so the view's isset()/empty() checks behave identically.
        if ($result->status === ScanResult::STATUS_ALREADY_SCANNED) {
            $array['scanned_by_name'] = $result->scannedByName;
        }

        if ($result->breakdown !== []) {
            $array['breakdown'] = $result->breakdown;
        }

        return $array;
    }

    /**
     * The recent-scan recap for this operator, newest first. Returns a plain
     * array the views can render without touching the session directly.
     *
     * @return array<int, array{status: string, message: string, reference: ?string, customer: ?string, tickets: int, at: string}>
     */
    private function history(Request $request): array
    {
        /** @var array<int, array{status: string, message: string, reference: ?string, customer: ?string, tickets: int, at: string}> $history */
        $history = $request->session()->get(self::HISTORY_KEY, []);

        return $history;
    }

    /**
     * Detect an accidental immediate re-scan of the exact ticket this operator
     * just scanned, and when found, mark the result so the banner shows a
     * distinct "you've just scanned this ticket" warning instead of the generic
     * already-scanned message.
     *
     * The guard is deliberately narrow: it fires only when the current scan
     * resolved to a known Order whose reference equals the single most recent
     * reference this operator scanned (stored in {@see LAST_REFERENCE_KEY}). It
     * remembers only the LAST one ticket — scanning any other ticket overwrites
     * the stored reference, so an older duplicate falls back to the normal
     * already-scanned message. Mutates $result in place, adding `just_scanned`
     * and overriding `message` when it matches.
     */
    private function flagJustScanned(Request $request, array &$result): void
    {
        /** @var Order|null $order */
        $order = $result['order'] ?? null;

        $reference = $order?->order_reference;

        // Only orders can be an accidental re-scan; unreadable/invalid/unknown
        // codes carry no reference and are never "the same ticket again".
        if ($reference === null) {
            return;
        }

        $lastReference = $request->session()->get(self::LAST_REFERENCE_KEY);

        if ($lastReference !== null && $lastReference === $reference) {
            $result['just_scanned'] = true;
            $result['message'] = 'You just scanned this ticket.';
        }
    }

    /**
     * Push one scan outcome onto the front of the session recap and trim it to
     * {@see HISTORY_LIMIT}. We store only display-safe scalars (no models) so
     * the session payload stays small and serialisable. Also records this
     * scan's Order_Reference as the "last" one so the next scan can detect an
     * accidental immediate re-scan of the same ticket.
     */
    private function remember(Request $request, array $result): void
    {
        /** @var Order|null $order */
        $order = $result['order'] ?? null;

        $entry = [
            'status' => $result['status'],
            'message' => $result['message'],
            'reference' => $order?->order_reference,
            'customer' => $order?->customer_name,
            'tickets' => (int) collect($result['breakdown'] ?? [])->sum('quantity'),
            'at' => now()->format('H:i:s'),
        ];

        $history = $this->history($request);
        array_unshift($history, $entry);
        $history = array_slice($history, 0, self::HISTORY_LIMIT);

        $request->session()->put(self::HISTORY_KEY, $history);

        // Remember the single most recent Order_Reference (only when this scan
        // resolved to one) so the next scan can spot a quick accidental repeat
        // of the SAME ticket. A non-order outcome (unreadable/invalid/unknown)
        // leaves the previous reference untouched.
        if ($order?->order_reference !== null) {
            $request->session()->put(self::LAST_REFERENCE_KEY, $order->order_reference);
        }
    }
}
