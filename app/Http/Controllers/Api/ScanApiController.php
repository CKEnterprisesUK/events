<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ScanController;
use App\Models\Event;
use App\Services\Operator;
use App\Services\RoleAuthorization;
use App\Services\ScanResult;
use App\Services\TicketCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The native iOS scanner's check-in endpoint — the JSON twin of the web
 * {@see ScanController}. (Requirements 5.1–5.10, 6.1, 6.2)
 *
 * This controller is deliberately thin: like the web scanner it gates on the
 * Scanner role's `ACTION_CHECK_IN` Gate (Requirements 5.1, 5.2) — any user whose
 * role does not permit check-in is denied 403 with all data unchanged — then
 * funnels either the scanned `payload` (a QR_Payload) or a manually typed
 * `reference` through the shared {@see TicketCheckInService::resolve()}. The
 * decision tree itself (what each outcome means, the atomic single check-in,
 * the Scan_Event recording) lives in the service, so the web and API surfaces
 * can never diverge on the Scan_Outcome.
 *
 * Every resolved outcome is a `200` response whose body is the Scan_Outcome
 * contract — the outcome (checked_in / already_scanned / rejected / failed /
 * invalid / unreadable) is the payload, not an HTTP error, so the door operator
 * always gets a structured result. Only a denied role produces a non-200 (403
 * from the Gate); a bad/expired token is rejected upstream by `auth:sanctum`.
 */
class ScanApiController extends Controller
{
    public function __construct(private readonly TicketCheckInService $checkIn) {}

    /**
     * Verify a scanned QR `payload` OR a manually typed `reference` and, when
     * valid and unscanned, atomically check the Order in. Serialises the shared
     * {@see ScanResult} to the Scan_Outcome JSON contract. (Requirements 5.1–5.10,
     * 6.1, 6.2)
     */
    public function scan(Request $request): JsonResponse
    {
        // 5.1 / 5.2 — the check-in Gate runs before anything is read or written,
        // exactly as the web scanner, so a role without check_in is denied 403
        // and no Order is ever resolved or mutated.
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $data = $request->validate([
            'payload' => ['nullable', 'string'],
            'reference' => ['nullable', 'string'],
            // The Event the operator chose to scan for (iOS Events tab). When
            // present the scan is scoped to it: an Order for a different Event
            // of the same Company is rejected as "wrong event". Optional for
            // backwards compatibility — a missing value accepts any of the
            // Company's Orders as before.
            'event_id' => ['nullable', 'integer'],
        ]);

        // Accept either a scanned QR payload or a manual Order_Reference. Both
        // are just strings to the service, which tells them apart by the token
        // separator and funnels both through the same decision tree (6.1); when
        // neither is supplied an empty string resolves to `unreadable` (6.2).
        $input = (string) ($data['payload'] ?? $data['reference'] ?? '');

        $expectedEventId = isset($data['event_id']) ? (int) $data['event_id'] : null;

        // Scanning is only allowed for a LIVE event — published and not
        // cancelled. When the operator pinned an event, verify it is scannable
        // (tenant-scoped, so a foreign id never matches) before touching any
        // Order. A draft/cancelled/unknown event is refused with a clear
        // message and nothing is read or written.
        if ($expectedEventId !== null) {
            $event = Event::query()->whereKey($expectedEventId)->first();

            if ($event === null || ! $event->is_published || $event->cancelled_at !== null) {
                return response()->json([
                    'status' => ScanResult::STATUS_REJECTED,
                    'message' => 'This event is not live, so tickets can’t be scanned for it.',
                ]);
            }
        }

        $result = $this->checkIn->resolve(
            $input,
            Operator::fromUser($request->user()),
            $expectedEventId,
        );

        return response()->json($this->serialise($result));
    }

    /**
     * Serialise a {@see ScanResult} to the Scan_Outcome JSON contract. Every
     * outcome carries `status` + `message`; the richer fields are included only
     * for the statuses that define them so the body matches the contract shape
     * exactly (no null padding):
     *
     *   - checked_in      reference, customer, scanned_at (ISO-8601), breakdown[]
     *   - already_scanned reference, customer, scanned_at (ISO-8601), scanned_by
     *   - rejected/failed/invalid/unreadable  status + message only
     *
     * @return array<string, mixed>
     */
    private function serialise(ScanResult $result): array
    {
        $body = [
            'status' => $result->status,
            'message' => $result->message,
        ];

        if ($result->status === ScanResult::STATUS_CHECKED_IN) {
            $body['reference'] = $result->order?->order_reference;
            $body['customer'] = $result->order?->customer_name;
            $body['scanned_at'] = $result->scannedAt?->toIso8601String();
            $body['breakdown'] = $result->breakdown;

            return $body;
        }

        if ($result->status === ScanResult::STATUS_ALREADY_SCANNED) {
            $body['reference'] = $result->order?->order_reference;
            $body['customer'] = $result->order?->customer_name;
            $body['scanned_at'] = $result->scannedAt?->toIso8601String();
            $body['scanned_by'] = $result->scannedByName;

            return $body;
        }

        return $body;
    }
}
