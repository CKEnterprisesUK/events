<?php

namespace App\Services;

use App\Jobs\SendTicketEmailJob;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * Fulfils a confirmed Order exactly once. Confirmation happens on two paths —
 * a paid Order marked paid by the `checkout.session.completed` webhook, and a
 * free-only Order confirmed immediately at checkout — and both hand off here.
 * (Requirements 10.9, 12.6, 14.1, 14.3)
 *
 * On fulfilment this service:
 *   1. commits the Order's held capacity from reserved to sold via
 *      {@see CapacityReservationService::commit()}, so availability stays
 *      consistent (`available = capacity - sold_count - reserved_count`) — the
 *      reserved bucket shrinks and the sold bucket grows by the Order's ticket
 *      quantities (Requirements 6.6, 6.7, 10.6);
 *   2. generates the single QR for the Order — its payload encodes
 *      `HMAC(secret, Order_Reference)` and is re-derivable from the stable
 *      Order_Reference, so nothing is stored (Requirements 14.1, 14.2);
 *   3. enqueues {@see SendTicketEmailJob} on the DB queue to send the branded
 *      ticket email with the QR (Requirement 14.3).
 *
 * ## Idempotency
 *
 * The capacity commit and the `fulfilled_at` stamp happen inside a single
 * transaction that re-reads the Order `FOR UPDATE`. An Order already carrying
 * `fulfilled_at` short-circuits, so a redelivered webhook, a retried job, or a
 * double confirmation never double-counts `sold_count` and never enqueues a
 * second ticket email. The commit itself is also clamp-idempotent as a backstop
 * ({@see CapacityReservationService::commit()}). The email is enqueued only when
 * this call is the one that actually stamped `fulfilled_at`.
 */
class OrderFulfilmentService
{
    public function __construct(
        private readonly CapacityReservationService $capacity,
        private readonly QrService $qr,
    ) {}

    /**
     * Fulfil a confirmed Order. No-op if the Order is not confirmed or has
     * already been fulfilled. Returns true when this call performed fulfilment
     * (committed capacity, stamped `fulfilled_at`, enqueued the email), false
     * when it was skipped as a duplicate or a non-confirmed Order.
     */
    public function fulfil(Order $order): bool
    {
        $qrPayload = $this->qr->payloadFor($order);

        $fulfilled = DB::transaction(function () use ($order): bool {
            // Re-read under a row lock so concurrent confirmations (webhook
            // redelivery racing a retry) serialise on this Order. (14.3)
            $locked = Order::withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->isConfirmed() || $locked->isFulfilled()) {
                // Not confirmed yet, gone, or already fulfilled → nothing to do.
                return false;
            }

            // Commit the held capacity reserved → sold for this Order's
            // Ticket_Types. (Requirements 6.6, 6.7, 10.6)
            $event = Event::withoutGlobalScopes()->find($locked->event_id);

            if ($event !== null) {
                $this->capacity->commit($event, $this->quantitiesFor($locked));
            }

            // Stamp the idempotency guard in the same transaction that committed
            // capacity, so it and the sold-count move commit together. (14.3)
            $locked->fulfilled_at = now();
            $locked->save();

            return true;
        });

        if (! $fulfilled) {
            return false;
        }

        // Enqueue the branded ticket email on the DB queue, only for the call
        // that actually fulfilled the Order. (Requirement 14.3)
        SendTicketEmailJob::dispatch($order->getKey(), $qrPayload);

        return true;
    }

    /**
     * The per-Ticket_Type quantities held by the Order, recovered from its
     * Ticket rows. Bypasses the tenant scope: fulfilment runs in service/queue
     * context with no resolved Company; the Order is supplied by the (already
     * scoped) caller. (Requirement 10.5)
     *
     * @return array<int, int>
     */
    private function quantitiesFor(Order $order): array
    {
        return Ticket::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->selectRaw('ticket_type_id, COUNT(*) as qty')
            ->groupBy('ticket_type_id')
            ->pluck('qty', 'ticket_type_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }
}
