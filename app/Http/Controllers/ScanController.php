<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\ScanEvent;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
 * {@see scan} is the authoritative server side. It:
 *   1. decodes + HMAC-verifies the payload via {@see QrService} — an
 *      undecodable payload yields an unreadable-code result (16.3); a payload
 *      whose recomputed HMAC does not match yields an invalid-code result
 *      (16.4, 16.5);
 *   2. loads the Order by its Order_Reference **through the tenant scope**, so
 *      an Order belonging to another Company simply is not found and is
 *      rejected (16.6) — the same not-found result covers a reference with no
 *      Order;
 *   3. rejects a voided Order — and any non-confirmed Order — with a failure
 *      result (16.10);
 *   4. reports an already-scanned Order with its previously recorded
 *      `scanned_at` (16.9);
 *   5. for a valid, unscanned, confirmed Order, performs the atomic single
 *      check-in `UPDATE ... SET scanned_at, scanned_by WHERE id = ? AND
 *      scanned_at IS NULL` and, iff it wrote exactly one row, returns the full
 *      Order breakdown of Ticket_Types and quantities (16.7, 16.8). A losing
 *      concurrent scan writes zero rows and falls back to the already-scanned
 *      result carrying the winner's `scanned_at`.
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

    public function __construct(private QrService $qr) {}

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
     * check the Order in. Records the outcome in the session recap and renders
     * the live scanner page with a structured `result` describing the outcome.
     * (Requirements 16.3–16.10)
     */
    public function scan(Request $request): View|JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $payload = (string) $request->input('payload', '');

        $result = $this->resolve($payload);

        $this->remember($request, $result);
        $this->logScanEvent($result);

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
     * Push one scan outcome onto the front of the session recap and trim it to
     * {@see HISTORY_LIMIT}. We store only display-safe scalars (no models) so
     * the session payload stays small and serialisable.
     */
    private function remember(Request $request, array $result): void
    {
        /** @var \App\Models\Order|null $order */
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
    }

    /**
     * Record this scan outcome centrally in `scan_events` so the live event
     * dashboard can show the whole door team's activity — successful check-ins
     * AND problems (duplicates, unreadable/invalid/foreign codes, void tickets)
     * — in real time, not just this operator's own session recap.
     *
     * This is a best-effort operational breadcrumb: the authoritative check-in
     * state is still `orders.scanned_at`, written in {@see resolve()}. A failure
     * to log here must never fail the scan itself, so it is swallowed.
     */
    private function logScanEvent(array $result): void
    {
        // Map the resolve() status to a ScanEvent outcome. An "unreadable" and a
        // verified-but-unknown code both carry no Order; the rest carry one.
        $outcome = match ($result['status']) {
            'checked_in' => ScanEvent::OUTCOME_SUCCESS,
            'already_scanned' => ScanEvent::OUTCOME_DUPLICATE,
            'rejected' => ScanEvent::OUTCOME_REJECTED,
            'failed' => ScanEvent::OUTCOME_FAILED,
            'unreadable' => ScanEvent::OUTCOME_UNREADABLE,
            default => ScanEvent::OUTCOME_INVALID,
        };

        /** @var Order|null $order */
        $order = $result['order'] ?? null;

        try {
            ScanEvent::create([
                // company_id is auto-filled from the tenant context by
                // BelongsToCompany; set event_id from the resolved Order.
                'event_id' => $order?->event_id,
                'order_id' => $order?->getKey(),
                'operator_id' => Auth::id(),
                'operator_name' => Auth::user()?->name,
                'outcome' => $outcome,
                'reference' => $order?->order_reference,
                'customer_name' => $order?->customer_name,
                'tickets' => (int) collect($result['breakdown'] ?? [])->sum('quantity'),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let logging break the door. The session recap and the
            // authoritative orders.scanned_at are already written.
        }
    }

    /**
     * The decision tree of Requirement 16.3–16.10, returning a structured
     * result the view renders. Keeping it pure of HTTP concerns keeps the
     * outcomes easy to assert directly in tests.
     *
     * @return array{status: string, message: string, order?: Order, scanned_at?: \Illuminate\Support\Carbon, breakdown?: array<int, array{name: string, quantity: int}>}
     */
    private function resolve(string $payload): array
    {
        $payload = trim($payload);

        // Two kinds of input reach this method:
        //   1. A scanned QR payload — the `reference.token` shape {@see
        //      QrService::payload()} produces. It carries a `.` and is always
        //      HMAC-verified (16.4, 16.5) so a tampered/foreign token is caught.
        //   2. A manually typed Order_Reference — the door fallback for when a
        //      QR won't scan (damaged print, dead screen, bad light). An
        //      Order_Reference is 12 uppercase [A-Z0-9] chars and never contains
        //      a `.`, so the separator cleanly tells the two apart.
        //
        // The manual-reference path skips the HMAC: a typed reference was never
        // a token, so there is nothing to recompute. It stays safe because the
        // Order lookup below runs through the tenant scope — an operator can
        // only ever check in their own Company's Orders, exactly as with a scan.
        if (! str_contains($payload, QrService::SEPARATOR)) {
            $reference = $this->normaliseReference($payload);

            // 16.3 — nothing usable typed/scanned at all is still unreadable.
            if ($reference === '') {
                return [
                    'status' => 'unreadable',
                    'message' => 'Unreadable code. Please try scanning again.',
                ];
            }

            return $this->checkIn($reference);
        }

        // 16.3 — a payload carrying a `.` but not the `reference.token` shape
        // (e.g. an empty side) is undecodable and unreadable.
        if ($this->qr->decode($payload) === null) {
            return [
                'status' => 'unreadable',
                'message' => 'Unreadable code. Please try scanning again.',
            ];
        }

        // 16.4, 16.5 — recompute the HMAC over the Order_Reference; a tampered
        // token or one minted under a different secret fails verification.
        $reference = $this->qr->verifyPayload($payload);

        if ($reference === null) {
            return [
                'status' => 'invalid',
                'message' => 'Invalid code. This ticket could not be verified.',
            ];
        }

        return $this->checkIn($reference);
    }

    /**
     * Normalise a manually typed Order_Reference to the stored form: trimmed
     * and uppercased, since references are generated uppercase but a tired
     * operator may type them in any case. Returns '' when nothing usable
     * remains.
     */
    private function normaliseReference(string $input): string
    {
        return strtoupper(trim($input));
    }

    /**
     * The shared tail of {@see resolve()} from the verified/typed
     * Order_Reference onward: tenant-scoped load (16.6), confirmed-state check
     * (16.10), already-scanned check (16.9) and the atomic single check-in
     * (16.7, 16.8). Both the scanned-payload and manual-reference paths funnel
     * through here so they behave identically once a reference is in hand.
     *
     * @return array{status: string, message: string, order?: Order, scanned_at?: \Illuminate\Support\Carbon, breakdown?: array<int, array{name: string, quantity: int}>}
     */
    private function checkIn(string $reference): array
    {

        // 16.6 — load through the tenant scope: an Order of another Company (or
        // no such Order) never matches and is rejected.
        $order = Order::query()->where('order_reference', $reference)->first();

        if ($order === null) {
            return [
                'status' => 'rejected',
                'message' => 'Rejected. This ticket does not belong to your organisation.',
            ];
        }

        // 16.10 — a voided Order (and any non-confirmed Order: reserved,
        // expired, cancelled, refunded, disputed) fails at scan.
        if (! $order->isConfirmed()) {
            return [
                'status' => 'failed',
                'message' => 'Failed. This ticket is no longer valid.',
                'order' => $order,
            ];
        }

        // 16.9 — an already-scanned Order reports its original scanned_at.
        if ($order->scanned_at !== null) {
            return [
                'status' => 'already_scanned',
                'message' => 'Already scanned.',
                'order' => $order,
                'scanned_at' => $order->scanned_at,
            ];
        }

        // 16.8 — atomic single check-in. The WHERE ... scanned_at IS NULL guard
        // means exactly one scan among any concurrent attempts writes a row.
        $now = now();

        $affected = Order::query()
            ->whereKey($order->getKey())
            ->whereNull('scanned_at')
            ->update([
                'scanned_at' => $now,
                'scanned_by' => Auth::id(),
            ]);

        if ($affected !== 1) {
            // Lost the race: another scan checked this Order in first. Report
            // the already-scanned result with the winner's scanned_at.
            $order->refresh();

            return [
                'status' => 'already_scanned',
                'message' => 'Already scanned.',
                'order' => $order,
                'scanned_at' => $order->scanned_at,
            ];
        }

        $order->refresh();

        // 16.7 — the full Order breakdown of Ticket_Types and quantities.
        return [
            'status' => 'checked_in',
            'message' => 'Checked in.',
            'order' => $order,
            'scanned_at' => $order->scanned_at,
            'breakdown' => $this->breakdown($order),
        ];
    }

    /**
     * The Order's Ticket_Type + quantity breakdown: one row per Ticket_Type
     * with the count of that Order's Tickets against it. (Requirement 16.7)
     *
     * @return array<int, array{name: string, quantity: int}>
     */
    private function breakdown(Order $order): array
    {
        $rows = DB::table('tickets')
            ->join('ticket_types', 'tickets.ticket_type_id', '=', 'ticket_types.id')
            ->where('tickets.order_id', $order->getKey())
            ->selectRaw('ticket_types.name as name, COUNT(*) as quantity')
            ->groupBy('ticket_types.id', 'ticket_types.name')
            ->orderBy('ticket_types.name')
            ->get();

        return $rows
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'quantity' => (int) $row->quantity,
            ])
            ->all();
    }
}
