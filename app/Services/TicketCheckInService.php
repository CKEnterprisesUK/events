<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\ScanEvent;
use Illuminate\Support\Facades\DB;

/**
 * The shared ticket check-in decision tree — written once, read by both the
 * web scanner (the `ScanController`) and the iOS/JSON API, so the two surfaces
 * can never diverge on what "already scanned",
 * "rejected" or "failed" means. (Requirements 5.3–5.10, 6.1, 6.2)
 *
 * The logic is lifted verbatim in behaviour from the web `ScanController`'s
 * private `resolve`/`checkIn`/`alreadyScanned`/`breakdown`, with two seams
 * opened so both callers can share it:
 *
 *   1. The operator identity is passed in as an {@see Operator} rather than
 *      read from `Auth`, so the web caller supplies `Auth::user()` and the API
 *      caller supplies the token's user.
 *   2. `ScanEvent` recording moves in here, so every caller records a Scan_Event
 *      identically under the active tenant scope. (Requirement 5.10)
 *
 * It runs entirely under the active TenantContext (bound by the web
 * `dashboard.tenant` middleware or the API's `ResolveApiTenant`), so Orders of
 * other Companies never match — the tenant-scoped load is the isolation
 * boundary (Requirement 5.5).
 */
final class TicketCheckInService
{
    public function __construct(private QrService $qr) {}

    /**
     * Resolve a scanned QR_Payload OR a manually typed Order_Reference to a
     * {@see ScanResult}, performing the atomic single check-in when valid and
     * recording a Scan_Event for the outcome. (Requirements 5.3–5.10, 6.1, 6.2)
     */
    public function resolve(string $payload, Operator $operator): ScanResult
    {
        $result = $this->decide($payload, $operator);

        $this->logScanEvent($result, $operator);

        return $result;
    }

    /**
     * The decision tree of Requirements 5.3–5.8/6.1–6.2, returning a structured
     * {@see ScanResult} the caller renders. Kept pure of HTTP concerns and of
     * the Scan_Event side effect so the outcomes stay easy to assert directly.
     */
    private function decide(string $payload, Operator $operator): ScanResult
    {
        $payload = trim($payload);

        // Two kinds of input reach this method:
        //   1. A scanned QR payload — the `reference.token` shape {@see
        //      QrService::payload()} produces. It carries a `.` and is always
        //      HMAC-verified (5.4) so a tampered/foreign token is caught.
        //   2. A manually typed Order_Reference — the door fallback for when a
        //      QR won't scan (damaged print, dead screen, bad light). An
        //      Order_Reference is 12 uppercase [A-Z0-9] chars and never contains
        //      a `.`, so the separator cleanly tells the two apart. (6.1)
        //
        // The manual-reference path skips the HMAC: a typed reference was never
        // a token, so there is nothing to recompute. It stays safe because the
        // Order lookup below runs through the tenant scope — an operator can
        // only ever check in their own Company's Orders, exactly as with a scan.
        if (! str_contains($payload, QrService::SEPARATOR)) {
            $reference = $this->normaliseReference($payload);

            // 5.3 / 6.2 — nothing usable typed/scanned at all is unreadable.
            if ($reference === '') {
                return ScanResult::unreadable();
            }

            return $this->checkIn($reference, $operator);
        }

        // 5.3 — a payload carrying a `.` but not the `reference.token` shape
        // (e.g. an empty side) is undecodable and unreadable.
        if ($this->qr->decode($payload) === null) {
            return ScanResult::unreadable();
        }

        // 5.4 — recompute the HMAC over the Order_Reference; a tampered token or
        // one minted under a different secret fails verification.
        $reference = $this->qr->verifyPayload($payload);

        if ($reference === null) {
            return ScanResult::invalid();
        }

        return $this->checkIn($reference, $operator);
    }

    /**
     * Normalise a manually typed Order_Reference to the stored form: trimmed
     * and uppercased, since references are generated uppercase but a tired
     * operator may type them in any case. Returns '' when nothing usable
     * remains. (Requirement 6.1)
     */
    private function normaliseReference(string $input): string
    {
        return strtoupper(trim($input));
    }

    /**
     * The shared tail from the verified/typed Order_Reference onward:
     * tenant-scoped load (5.5), confirmed-state check (5.6), already-scanned
     * check (5.7) and the atomic single check-in (5.8). Both the scanned-payload
     * and manual-reference paths funnel through here so they behave identically
     * once a reference is in hand.
     */
    private function checkIn(string $reference, Operator $operator): ScanResult
    {
        // 5.5 — load through the tenant scope: an Order of another Company (or
        // no such Order) never matches and is rejected.
        $order = Order::query()->where('order_reference', $reference)->first();

        if ($order === null) {
            // The tenant scope means this covers both a reference that matches
            // no Order at all (e.g. a mistyped manual entry) and one belonging
            // to another Company — the scope hides the difference on purpose, so
            // a single "not found" message fits both without leaking either.
            return ScanResult::rejected();
        }

        // 5.6 — a voided Order (and any non-confirmed Order: reserved, expired,
        // cancelled, refunded, disputed) fails at scan.
        if (! $order->isConfirmed()) {
            return ScanResult::failed($order);
        }

        // 5.7 — an already-scanned Order reports its original scanned_at, plus
        // who checked it in, so a second operator sees it wasn't them.
        if ($order->scanned_at !== null) {
            return $this->alreadyScanned($order);
        }

        // 5.8 — atomic single check-in. The WHERE ... scanned_at IS NULL guard
        // means exactly one scan among any concurrent attempts writes a row.
        $now = now();

        $affected = Order::query()
            ->whereKey($order->getKey())
            ->whereNull('scanned_at')
            ->update([
                'scanned_at' => $now,
                'scanned_by' => $operator->id,
            ]);

        if ($affected !== 1) {
            // Lost the race: another scan checked this Order in first. Report
            // the already-scanned result with the winner's scanned_at and name.
            $order->refresh();

            return $this->alreadyScanned($order);
        }

        $order->refresh();

        // 5.8 — the full Order breakdown of Ticket_Types and quantities.
        return ScanResult::checkedIn($order, $order->scanned_at, $this->breakdown($order));
    }

    /**
     * The already-scanned result for an Order that was checked in earlier.
     * Alongside the original `scanned_at`, it carries the name of the operator
     * who scanned it so a second operator at the door can see it was a prior
     * check-in (and by whom), not their own. The name is null when the Order
     * was never scanned by a still-existing user (e.g. the operator's account
     * was deleted — `scanned_by` is nullOnDelete) or predates attribution.
     * (Requirement 5.7)
     */
    private function alreadyScanned(Order $order): ScanResult
    {
        // Load the operator through the relationship if it isn't already set.
        // scanned_by is nullOnDelete, so a since-deleted operator yields null
        // and we simply omit the name rather than inventing one.
        $operator = $order->relationLoaded('scannedBy')
            ? $order->scannedBy
            : $order->scannedBy()->first();

        return ScanResult::alreadyScanned($order, $order->scanned_at, $operator?->name);
    }

    /**
     * The Order's Ticket_Type + quantity breakdown: one row per Ticket_Type
     * with the count of that Order's Tickets against it. (Requirement 5.8)
     *
     * @return list<array{name: string, quantity: int}>
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

    /**
     * Record this scan outcome centrally in `scan_events` so the live event
     * dashboard can show the whole door team's activity — successful check-ins
     * AND problems (duplicates, unreadable/invalid/foreign codes, void tickets)
     * — in real time, not just one operator's own session recap. (Req 5.10)
     *
     * This is a best-effort operational breadcrumb: the authoritative check-in
     * state is still `orders.scanned_at`, written in {@see checkIn()}. A failure
     * to log here must never fail the scan itself, so it is swallowed.
     */
    private function logScanEvent(ScanResult $result, Operator $operator): void
    {
        // Map the ScanResult status to a ScanEvent outcome. An "unreadable" and
        // a verified-but-unknown code both carry no Order; the rest carry one.
        $outcome = match ($result->status) {
            ScanResult::STATUS_CHECKED_IN => ScanEvent::OUTCOME_SUCCESS,
            ScanResult::STATUS_ALREADY_SCANNED => ScanEvent::OUTCOME_DUPLICATE,
            ScanResult::STATUS_REJECTED => ScanEvent::OUTCOME_REJECTED,
            ScanResult::STATUS_FAILED => ScanEvent::OUTCOME_FAILED,
            ScanResult::STATUS_UNREADABLE => ScanEvent::OUTCOME_UNREADABLE,
            default => ScanEvent::OUTCOME_INVALID,
        };

        $order = $result->order;

        try {
            ScanEvent::create([
                // company_id is auto-filled from the tenant context by
                // BelongsToCompany; set event_id from the resolved Order.
                'event_id' => $order?->event_id,
                'order_id' => $order?->getKey(),
                'operator_id' => $operator->id,
                'operator_name' => $operator->name,
                'outcome' => $outcome,
                'reference' => $order?->order_reference,
                'customer_name' => $order?->customer_name,
                'tickets' => $result->ticketCount(),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never let logging break the door. The session recap and the
            // authoritative orders.scanned_at are already written.
        }
    }
}
