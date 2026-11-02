<?php

namespace App\Services;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reserves and releases Ticket_Type capacity for checkouts, guaranteeing that
 * concurrent requests can never oversell. (Requirements 5.6, 6.6, 6.7, 6.8,
 * 10.6, 10.7, 18.3)
 *
 * How it serializes:
 *   Every reserve/release runs inside a single DB transaction that first takes
 *   `SELECT ... FOR UPDATE` locks — on the Event capacity row and on each
 *   requested Ticket_Type row — in a deterministic order (Event first, then
 *   Ticket_Types by ascending id). Because concurrent checkouts touching the
 *   same rows block on those locks, the availability check and the count
 *   mutation happen atomically: no two transactions can both read "enough
 *   room" and then both commit an over-reservation. Locking in a fixed order
 *   also avoids deadlocks between transactions that touch overlapping rows.
 *
 * Availability rule:
 *   Remaining available for a Ticket_Type = `capacity - sold_count -
 *   reserved_count` (active reserved). A request is admissible only if, for
 *   *every* requested Ticket_Type, the requested quantity fits its remaining
 *   available AND the total requested across the Event fits the Event's overall
 *   remaining capacity (when the Event sets one; NULL = unlimited). If any part
 *   of the request does not fit, the whole request is rejected atomically —
 *   nothing is reserved and all counts are left unchanged. (Requirements 6.7,
 *   6.8, 10.6, 5.6)
 *
 * Reservation window:
 *   A successful reserve returns the `reserved_until` instant — now +
 *   RESERVATION_WINDOW_SECONDS (1 hour), aligned with the Stripe Checkout
 *   Session's payment window. The caller snapshots this on the Order; the
 *   scheduled release job releases reservations whose window has elapsed AND
 *   whose Stripe Session is confirmed dead. (Requirements 10.6, 10.7)
 *
 * Release:
 *   `release()` returns held units back to `reserved_count` and is idempotent —
 *   it never drives `reserved_count` below zero, so releasing the same Order's
 *   hold twice (expiry racing a cancel, a retried job, etc.) is safe. It is the
 *   inverse used on expiry/cancel/failure. (Requirements 10.7, 6.6)
 */
class CapacityReservationService
{
    /**
     * The reservation window, in seconds. A reserve holds capacity for this
     * long before it must be confirmed (paid/free-confirmed) or released.
     *
     * Set to 3600s (1 hour) and deliberately aligned with the Stripe Checkout
     * Session's `expires_at`: the seat is held for exactly as long as Stripe
     * will still accept the payment. A slow-but-successful payment (bank
     * 3-D Secure delays, a customer who dawdles on the hosted page) can no
     * longer land AFTER its seat was handed back, which is what produced the
     * money-in/no-tickets state. The sweeper additionally verifies with Stripe
     * before releasing, so even an elapsed window never releases a seat whose
     * payment succeeded. (Requirements 10.6, 10.7; reservation-vs-payment race fix)
     */
    public const RESERVATION_WINDOW_SECONDS = 3600;

    /**
     * Reserve capacity for the given quantities against an Event, atomically.
     *
     * @param  Event  $event  The Event whose overall capacity also constrains the reservation.
     * @param  array<int, int>  $quantities  Map of Ticket_Type id => quantity to reserve (qty > 0).
     * @return Carbon The `reserved_until` instant (now + 900s) to snapshot on the Order.
     *
     * @throws InsufficientCapacityException When any requested quantity exceeds remaining availability (nothing reserved).
     * @throws \InvalidArgumentException When quantities are malformed or reference Ticket_Types outside the Event.
     */
    public function reserve(Event $event, array $quantities): Carbon
    {
        $quantities = $this->normalizeQuantities($quantities);

        if ($quantities === []) {
            // Nothing to reserve; still return a window so callers have a uniform contract.
            return Carbon::now()->addSeconds(self::RESERVATION_WINDOW_SECONDS);
        }

        return DB::transaction(function () use ($event, $quantities): Carbon {
            // Lock the Event capacity row first, then the Ticket_Type rows in a
            // deterministic (ascending id) order to serialize concurrent
            // checkouts and avoid deadlocks. (Requirements 6.8, 18.3)
            $lockedEvent = $this->lockEvent($event);
            $ticketTypes = $this->lockTicketTypes($lockedEvent, array_keys($quantities));

            $eventRequested = 0;

            foreach ($quantities as $ticketTypeId => $qty) {
                $ticketType = $ticketTypes[$ticketTypeId];

                // Capacity-mode-aware per-Ticket_Type availability:
                //   - capped:      enforced by its own per-type ceiling here
                //                  AND by the Event-overall check below.
                //   - shared_pool: NO per-type ceiling — its units still
                //                  accumulate into $eventRequested so the
                //                  Event-overall check alone governs it.
                // (Requirements 2.5, 2.6, 2.7, 2.8, 6.6, 6.7, 10.6)
                if ($ticketType->isCapped()) {
                    $available = $ticketType->capacity - $ticketType->sold_count - $ticketType->reserved_count;

                    if ($qty > $available) {
                        throw new InsufficientCapacityException(
                            "Insufficient availability for ticket type {$ticketTypeId}: requested {$qty}, {$available} available."
                        );
                    }
                }

                $eventRequested += $qty;
            }

            // Overall Event capacity, when set (NULL = unlimited). Counts
            // confirmed + active-reserved across all of the Event's Ticket_Types.
            // (Requirement 5.6)
            if ($lockedEvent->capacity !== null) {
                $eventCommitted = $this->eventCommittedAndReserved($lockedEvent);
                $eventAvailable = $lockedEvent->capacity - $eventCommitted;

                if ($eventRequested > $eventAvailable) {
                    throw new InsufficientCapacityException(
                        "Insufficient overall event capacity: requested {$eventRequested}, {$eventAvailable} available."
                    );
                }
            }

            // All checks passed — commit the holds. (Requirement 10.6)
            foreach ($quantities as $ticketTypeId => $qty) {
                TicketType::withoutGlobalScopes()
                    ->whereKey($ticketTypeId)
                    ->update(['reserved_count' => DB::raw("reserved_count + {$qty}")]);
            }

            return Carbon::now()->addSeconds(self::RESERVATION_WINDOW_SECONDS);
        });
    }

    /**
     * Release previously reserved capacity back to each Ticket_Type, atomically
     * and idempotently. Used on expiry/cancel/failure. (Requirements 10.7, 6.6)
     *
     * @param  Event  $event  The Event the reservation was made against.
     * @param  array<int, int>  $quantities  Map of Ticket_Type id => quantity to release (qty > 0).
     */
    public function release(Event $event, array $quantities): void
    {
        $quantities = $this->normalizeQuantities($quantities);

        if ($quantities === []) {
            return;
        }

        DB::transaction(function () use ($event, $quantities): void {
            $this->lockEvent($event);
            $ticketTypes = $this->lockTicketTypes($event, array_keys($quantities));

            foreach ($quantities as $ticketTypeId => $qty) {
                $ticketType = $ticketTypes[$ticketTypeId];

                // Idempotent: never drive reserved_count below zero, so a
                // double release (expiry racing cancel, retried job) is a no-op
                // once the hold is already gone. (Requirement 10.7)
                $release = min($qty, $ticketType->reserved_count);

                if ($release <= 0) {
                    continue;
                }

                TicketType::withoutGlobalScopes()
                    ->whereKey($ticketTypeId)
                    ->update(['reserved_count' => DB::raw("reserved_count - {$release}")]);
            }
        });
    }

    /**
     * Commit previously reserved capacity: convert held units from
     * `reserved_count` to `sold_count` for each Ticket_Type, atomically. Called
     * when an Order is confirmed (paid via the webhook, or free-confirmed at
     * checkout) so the availability identity `available = capacity - sold_count
     * - reserved_count` stays consistent — total committed capacity is
     * unchanged, it just moves from the reserved bucket to the sold bucket.
     * (Requirements 6.6, 6.7, 10.6, 14.1)
     *
     * Idempotent: like {@see release()} it never drives `reserved_count` below
     * zero, so committing the same Order's hold twice (a redelivered webhook, a
     * retried fulfilment) moves at most the units still reserved and is a no-op
     * once they are already committed. Callers additionally guard on the
     * Order's `fulfilled_at` so the sold bucket is never double-incremented; the
     * clamp here is the defence-in-depth backstop.
     *
     * @param  Event  $event  The Event the reservation was made against.
     * @param  array<int, int>  $quantities  Map of Ticket_Type id => quantity to commit (qty > 0).
     */
    public function commit(Event $event, array $quantities): void
    {
        $quantities = $this->normalizeQuantities($quantities);

        if ($quantities === []) {
            return;
        }

        DB::transaction(function () use ($event, $quantities): void {
            $this->lockEvent($event);
            $ticketTypes = $this->lockTicketTypes($event, array_keys($quantities));

            foreach ($quantities as $ticketTypeId => $qty) {
                $ticketType = $ticketTypes[$ticketTypeId];

                // Only commit units that are still held, so a double commit
                // cannot move more than was reserved nor drive reserved_count
                // negative. (Requirement 10.6 idempotency)
                $commit = min($qty, $ticketType->reserved_count);

                if ($commit <= 0) {
                    continue;
                }

                TicketType::withoutGlobalScopes()
                    ->whereKey($ticketTypeId)
                    ->update([
                        'reserved_count' => DB::raw("reserved_count - {$commit}"),
                        'sold_count' => DB::raw("sold_count + {$commit}"),
                    ]);
            }
        });
    }

    /**
     * Directly add units to `sold_count` for an Order whose hold has ALREADY
     * been released — the recovery case where a reserved Order was swept to
     * `expired` (its `reserved_count` decremented) and only later confirmed as
     * paid by Stripe. There is nothing left in `reserved_count` to move, so
     * {@see commit()} (which clamps at the held units) would commit zero and
     * leave `sold_count` wrong. This path bumps `sold_count` straight up
     * instead.
     *
     * Capacity policy: payment is authoritative, so the seats are ALWAYS
     * committed — even if the Event is now at or over capacity because the held
     * seats were resold in the gap. When that happens the commit is still
     * applied and `true` is returned via `$oversold`, so the caller can log /
     * alert rather than silently oversell. This can only tip over capacity in
     * the genuinely-simultaneous last-seat case, which the aligned window +
     * Stripe-checked sweeper otherwise prevent. (Reservation-vs-payment race fix)
     *
     * @param  array<int, int>  $quantities  Map of Ticket_Type id => quantity to add to sold.
     * @return bool True when committing these units pushed any Ticket_Type (or the Event overall) past capacity.
     */
    public function commitSoldDirect(Event $event, array $quantities): bool
    {
        $quantities = $this->normalizeQuantities($quantities);

        if ($quantities === []) {
            return false;
        }

        return DB::transaction(function () use ($event, $quantities): bool {
            $lockedEvent = $this->lockEvent($event);
            $ticketTypes = $this->lockTicketTypes($lockedEvent, array_keys($quantities));

            $oversold = false;

            foreach ($quantities as $ticketTypeId => $qty) {
                $ticketType = $ticketTypes[$ticketTypeId];

                // Detect a per-type overshoot (capped types only) for alerting;
                // we honour the payment regardless.
                if ($ticketType->isCapped()) {
                    $availableAfter = $ticketType->capacity - $ticketType->sold_count - $ticketType->reserved_count - $qty;

                    if ($availableAfter < 0) {
                        $oversold = true;
                    }
                }

                TicketType::withoutGlobalScopes()
                    ->whereKey($ticketTypeId)
                    ->update(['sold_count' => DB::raw("sold_count + {$qty}")]);
            }

            // Event-overall overshoot (when the Event sets a cap). The sold_count
            // increments above have already been applied in this transaction, so
            // this fresh sum reflects the POST-commit committed+reserved total —
            // compare it directly against the Event capacity.
            if ($lockedEvent->capacity !== null
                && $this->eventCommittedAndReserved($lockedEvent) > $lockedEvent->capacity) {
                $oversold = true;
            }

            return $oversold;
        });
    }

    /**
     * Return previously *sold* capacity back to availability: decrement
     * `sold_count` for each Ticket_Type, atomically. Called when a confirmed
     * Order that had committed capacity is cancelled/refunded/voided so the
     * availability identity `available = capacity - sold_count - reserved_count`
     * stays consistent — the sold bucket shrinks by the Order's ticket
     * quantities, returning that capacity for resale. (Requirements 6.6, 6.7,
     * 17.3, 17.4)
     *
     * Idempotent: like {@see release()} and {@see commit()} it never drives
     * `sold_count` below zero, so releasing the same Order's sold units twice
     * (a dashboard refund whose `charge.refunded` webhook later arrives, a
     * retried job) moves at most the units still counted and is a no-op once
     * they are already returned. Callers additionally guard on the Order's
     * status so the sold bucket is never double-decremented; the clamp here is
     * the defence-in-depth backstop.
     *
     * @param  Event  $event  The Event the Order was placed against.
     * @param  array<int, int>  $quantities  Map of Ticket_Type id => quantity to return (qty > 0).
     */
    public function releaseSold(Event $event, array $quantities): void
    {
        $quantities = $this->normalizeQuantities($quantities);

        if ($quantities === []) {
            return;
        }

        DB::transaction(function () use ($event, $quantities): void {
            $this->lockEvent($event);

            // Return-of-sold is a compensating cleanup (cancel/refund/void), so
            // it tolerates Ticket_Type ids that are not part of this Event
            // rather than rejecting them: it simply returns capacity for the
            // ones that are, leaving any stray id untouched. Reserve/commit stay
            // strict via lockTicketTypes(); this path uses the lenient lookup.
            $ticketTypes = $this->lockOwnedTicketTypes($event, array_keys($quantities));

            foreach ($quantities as $ticketTypeId => $qty) {
                $ticketType = $ticketTypes[$ticketTypeId] ?? null;

                if ($ticketType === null) {
                    continue;
                }

                // Idempotent: never drive sold_count below zero, so a double
                // release-of-sold (dashboard refund racing the refund webhook,
                // a retried job) is a no-op once the units are already returned.
                // (Requirement 17.4)
                $release = min($qty, $ticketType->sold_count);

                if ($release <= 0) {
                    continue;
                }

                TicketType::withoutGlobalScopes()
                    ->whereKey($ticketTypeId)
                    ->update(['sold_count' => DB::raw("sold_count - {$release}")]);
            }
        });
    }

    /**
     * Take a `FOR UPDATE` lock on the Event capacity row and return the locked
     * (fresh) instance. Bypasses the tenant scope because capacity accounting
     * runs in service context where no request tenant is resolved; the Event is
     * supplied by the (already tenant-scoped) caller.
     */
    private function lockEvent(Event $event): Event
    {
        return Event::withoutGlobalScopes()
            ->whereKey($event->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Take `FOR UPDATE` locks on the requested Ticket_Type rows (ascending id
     * for a stable lock order) and return them keyed by id. Rejects ids that do
     * not belong to the given Event so a request can never reserve against
     * another Event's (or Company's) Ticket_Types.
     *
     * @param  array<int, int>  $ticketTypeIds
     * @return array<int, TicketType>
     */
    private function lockTicketTypes(Event $event, array $ticketTypeIds): array
    {
        sort($ticketTypeIds);

        $ticketTypes = TicketType::withoutGlobalScopes()
            ->where('event_id', $event->getKey())
            ->whereIn('id', $ticketTypeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ticketTypeIds as $id) {
            if (! $ticketTypes->has($id)) {
                throw new \InvalidArgumentException(
                    "Ticket type {$id} does not belong to event {$event->getKey()}."
                );
            }
        }

        return $ticketTypes->all();
    }

    /**
     * Take `FOR UPDATE` locks on the requested Ticket_Type rows (ascending id
     * for a stable lock order) that belong to the given Event, keyed by id.
     * Unlike {@see lockTicketTypes()} this is lenient: ids that do not belong to
     * the Event are simply absent from the result rather than raising. Used by
     * the compensating {@see releaseSold()} cleanup, which must never fail on a
     * stray id — it only returns capacity for the Event's own Ticket_Types.
     *
     * @param  array<int, int>  $ticketTypeIds
     * @return array<int, TicketType>
     */
    private function lockOwnedTicketTypes(Event $event, array $ticketTypeIds): array
    {
        sort($ticketTypeIds);

        return TicketType::withoutGlobalScopes()
            ->where('event_id', $event->getKey())
            ->whereIn('id', $ticketTypeIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();
    }

    /**
     * Sum of confirmed (`sold_count`) and active-reserved (`reserved_count`)
     * across every Ticket_Type of the Event, used to enforce the Event's
     * overall capacity. Read after the Ticket_Type rows are locked so it
     * reflects this transaction's committed view. (Requirement 5.6)
     */
    private function eventCommittedAndReserved(Event $event): int
    {
        return (int) TicketType::withoutGlobalScopes()
            ->where('event_id', $event->getKey())
            ->sum(DB::raw('sold_count + reserved_count'));
    }

    /**
     * Validate and normalize the quantities map: integer ids => positive
     * integer quantities. Drops nothing silently — malformed entries raise.
     *
     * @param  array<int|string, mixed>  $quantities
     * @return array<int, int>
     */
    private function normalizeQuantities(array $quantities): array
    {
        $normalized = [];

        foreach ($quantities as $ticketTypeId => $qty) {
            if (! is_numeric($ticketTypeId) || (int) $ticketTypeId <= 0) {
                throw new \InvalidArgumentException("Invalid ticket type id: {$ticketTypeId}.");
            }

            if (! is_int($qty) && ! (is_numeric($qty) && (int) $qty == $qty)) {
                throw new \InvalidArgumentException("Quantity for ticket type {$ticketTypeId} must be an integer.");
            }

            $qty = (int) $qty;

            if ($qty < 0) {
                throw new \InvalidArgumentException("Quantity for ticket type {$ticketTypeId} must be non-negative.");
            }

            if ($qty === 0) {
                continue;
            }

            $normalized[(int) $ticketTypeId] = $qty;
        }

        return $normalized;
    }
}
