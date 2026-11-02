<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Operator;
use App\Services\RoleAuthorization;
use App\Services\ScanResult;
use App\Services\TicketCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The native iOS scanner's single-Order surface: view one Order's detail and
 * manually mark it as entered (a staffed check-in from the Customers flow, as
 * opposed to a camera scan).
 *
 * Two distinct authorities apply:
 *   - {@see show()} is gated on `ACTION_MANAGE_ORDERS` (Owner, Admin,
 *     Box_Office, Accountant) — the same authority as viewing the Customers
 *     roster, since this is read-only order detail.
 *   - {@see checkIn()} is gated on `ACTION_CHECK_IN` (Owner, Admin, Box_Office,
 *     Scanner) — the door authority. This DELIBERATELY excludes the Accountant:
 *     an accountant may read orders but must not check attendees in. So "box
 *     office and above, not accountants" is enforced by the existing role
 *     matrix with no new rule.
 *
 * Manual check-in funnels through the shared {@see TicketCheckInService} by the
 * Order_Reference, so it behaves identically to a camera scan or the manual
 * reference entry on the Scan tab — the same confirmed/already-scanned checks,
 * the same atomic single check-in, the same Scan_Event recording. Everything
 * runs under `api.tenant`, so a foreign Order never resolves.
 */
class OrderApiController extends Controller
{
    public function __construct(private readonly TicketCheckInService $checkIn) {}

    /**
     * One Order's detail, tenant-scoped. Carries the fields the door team needs
     * to confirm who is in front of them before a manual check-in: customer,
     * event, status, the ticket-type breakdown and the current check-in state.
     */
    public function show(Request $request, int $order): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_ORDERS);

        $model = Order::query()
            ->with(['event', 'tickets.ticketType', 'scannedBy'])
            ->whereKey($order)
            ->first();

        if ($model === null) {
            abort(404);
        }

        return response()->json($this->serialise($model));
    }

    /**
     * Manually mark an Order as entered — a staffed check-in by reference. Gated
     * on `ACTION_CHECK_IN` so an Accountant (who can view orders) cannot check
     * anyone in. Resolves through the shared service scoped to the Order's own
     * Event, so it applies the identical decision tree as a camera scan and
     * records the Scan_Event. Returns the same Scan_Outcome contract as
     * `POST /api/scan` so the client can reuse its outcome handling.
     */
    public function checkIn(Request $request, int $order): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $model = Order::query()->with('event')->whereKey($order)->first();

        if ($model === null) {
            abort(404);
        }

        // Manual check-in is only allowed for a LIVE event — published and not
        // cancelled — exactly like a camera scan. A draft/cancelled event is
        // refused with a clear message and nothing is mutated.
        $event = $model->event;
        if ($event !== null && (! $event->is_published || $event->cancelled_at !== null)) {
            return response()->json([
                'status' => ScanResult::STATUS_REJECTED,
                'message' => 'This event is not live, so tickets can’t be checked in for it.',
            ]);
        }

        $result = $this->checkIn->resolve(
            (string) $model->order_reference,
            Operator::fromUser($request->user()),
            $model->event_id !== null ? (int) $model->event_id : null,
        );

        return response()->json($this->serialiseOutcome($result));
    }

    /**
     * The full Order detail payload for {@see show()}.
     *
     * @return array<string, mixed>
     */
    private function serialise(Order $order): array
    {
        return [
            'id' => (int) $order->getKey(),
            'reference' => $order->order_reference,
            'customer_name' => $order->customer_name,
            'customer_email' => $order->customer_email,
            'event_id' => $order->event_id !== null ? (int) $order->event_id : null,
            'event_name' => $order->event?->name,
            'status' => $order->status,
            'is_confirmed' => $order->isConfirmed(),
            'order_total_minor' => (int) $order->order_total_minor,
            'scanned_at' => $order->scanned_at?->toIso8601String(),
            'scanned_by' => $order->scannedBy?->name,
            'breakdown' => $this->breakdown($order),
        ];
    }

    /**
     * The Order's Ticket_Type + quantity breakdown for the detail payload.
     *
     * @return list<array{name: string, quantity: int}>
     */
    private function breakdown(Order $order): array
    {
        return $order->tickets
            ->groupBy(fn ($ticket) => $ticket->ticketType?->name ?? 'Ticket')
            ->map(fn ($group, $name): array => [
                'name' => (string) $name,
                'quantity' => $group->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Serialise a {@see ScanResult} to the Scan_Outcome JSON contract — the
     * same shape {@see ScanApiController::serialise()} produces, so the client
     * decodes a manual check-in exactly as a scan.
     *
     * @return array<string, mixed>
     */
    private function serialiseOutcome(ScanResult $result): array
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
