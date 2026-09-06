<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
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
    public function __construct(private QrService $qr) {}

    /**
     * The scanner page: opens the camera in the browser and POSTs decoded
     * payloads to {@see scan}. (Requirements 16.1, 16.2)
     */
    public function index(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        return view('dashboard.scan.index');
    }

    /**
     * Verify a decoded QR payload and, when valid and unscanned, atomically
     * check the Order in. Renders the scanner page with a structured `result`
     * describing the outcome. (Requirements 16.3–16.10)
     */
    public function scan(Request $request): View
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $payload = (string) $request->input('payload', '');

        return view('dashboard.scan.index', [
            'result' => $this->resolve($payload),
        ]);
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
        // 16.3 — an undecodable payload (wrong shape) is unreadable.
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
