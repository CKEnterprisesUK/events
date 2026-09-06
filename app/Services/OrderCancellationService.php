<?php

namespace App\Services;

use App\Http\Controllers\OrderController;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Support\Facades\DB;

/**
 * The single place an Order is cancelled, refunded, or disputed. Both entry
 * points converge here — the Admin dashboard ({@see OrderController})
 * and the Stripe refund/dispute webhooks ({@see WebhookProcessor}) — so a
 * dashboard refund and a later `charge.refunded` webhook for the same Order
 * produce the same end state without applying the effect twice. (Requirements
 * 17.1–17.5)
 *
 * What a terminal transition does, all inside one `FOR UPDATE` transaction on
 * the Order:
 *   1. Move the Order to its terminal status (cancelled/refunded/disputed).
 *   2. Void every one of the Order's Tickets so the shared QR fails at scan.
 *      (Requirements 17.3, 16.10)
 *
 * A PARTIAL refund ({@see partialRefund()}) is the exception that does NOT run
 * the terminal transition: it issues a Stripe refund for part of the total and
 * records it against the Order's cumulative `refunded_total_minor` while the
 * Order stays `paid` with valid Tickets. Only once cumulative refunds reach the
 * full order total does it converge on the same terminal transition as a full
 * refund. (Requirement 17.2)
 *   3. Return the capacity the Order held — sold capacity (a confirmed Order)
 *      is returned via {@see CapacityReservationService::releaseSold()};
 *      still-reserved capacity (a reserved Order being cancelled before payment)
 *      is returned via {@see CapacityReservationService::release()}. So
 *      `available = capacity - sold_count - reserved_count` stays correct.
 *      (Requirements 6.6, 6.7)
 *
 * ## Idempotency
 *
 * The Order is re-read `FOR UPDATE` and a transition is applied only if the
 * Order is not already in a terminal (cancelled/refunded/disputed/voided)
 * state. An Order already terminal short-circuits — no second capacity return,
 * no second void, and (for refunds) no second Stripe refund. This is what makes
 * the dashboard path and the webhook path converge: whichever runs first
 * performs the effect, the other becomes a no-op. The Stripe refund call is
 * issued by the dashboard path *before* the transition is committed; the
 * webhook path never calls Stripe (it is reacting to a refund Stripe already
 * made).
 */
class OrderCancellationService
{
    /**
     * Statuses from which no further terminal transition should be applied.
     * Reaching any of these means the Order's tickets are already voided and
     * its capacity already returned.
     */
    private const TERMINAL_STATUSES = [
        Order::STATUS_CANCELLED,
        Order::STATUS_REFUNDED,
        Order::STATUS_DISPUTED,
        Order::STATUS_VOIDED,
        Order::STATUS_EXPIRED,
    ];

    public function __construct(
        private readonly CapacityReservationService $capacity,
        private readonly StripePaymentService $stripe,
    ) {}

    /**
     * Cancel an Order: void its Tickets and return its capacity. A reserved
     * Order returns its still-held reservation; a confirmed (paid/free) Order
     * returns its sold capacity. No Stripe interaction — cancellation does not
     * move money (a paid Order is refunded via {@see refund()}). Idempotent.
     * (Requirements 17.1, 17.3)
     */
    public function cancel(Order $order): bool
    {
        return $this->applyTerminal($order, Order::STATUS_CANCELLED);
    }

    /**
     * Refund an Order from the dashboard: issue the Stripe refund on the
     * Company's connected account for a paid Order (mocked in tests), then mark
     * the Order refunded, void its Tickets, and return its sold capacity.
     * Idempotent — an already-terminal Order is left untouched and no refund is
     * issued, so a redelivered `charge.refunded` webhook after this does not
     * double-apply. (Requirements 17.1, 17.2, 17.3, 17.4)
     */
    public function refund(Order $order): bool
    {
        // Only a paid Order that still carries a charge is refunded through
        // Stripe; a free-confirmed or already-terminal Order moves straight to
        // the void/capacity transition. Issue the refund BEFORE the DB
        // transition so a Stripe failure aborts the whole operation and leaves
        // the Order (and its money) unchanged. (Requirement 17.2)
        if ($order->status === Order::STATUS_PAID && $order->stripe_charge_id !== null) {
            $company = $order->company;

            $this->stripe->refundCharge(
                connectedAccountId: (string) $company->stripe_account_id,
                chargeId: (string) $order->stripe_charge_id,
                amountMinor: $order->order_total_minor,
            );
        }

        return $this->applyTerminal($order, Order::STATUS_REFUNDED);
    }

    /**
     * Partially refund a paid Order from the dashboard: issue a Stripe refund
     * for $amountMinor on the Company's connected account, then record the
     * amount against the Order's cumulative `refunded_total_minor`. The Order
     * stays `paid` and its Tickets stay valid (a partial refund does not void
     * tickets or return capacity) UNLESS this refund brings the cumulative total
     * up to the full order total, in which case it converges on the same
     * terminal transition as a full {@see refund()} — flipping the Order to
     * `refunded`, voiding its Tickets, and returning its sold capacity.
     * (Requirement 17.2)
     *
     * The refund amount must be positive and must not exceed the amount still
     * refundable on the Order ({@see Order::refundableRemainingMinor()}); an
     * out-of-range amount throws {@see \InvalidArgumentException} before any
     * Stripe call so no money moves. The remaining balance is re-checked under a
     * `FOR UPDATE` row lock, so two concurrent partial refunds can never
     * over-refund past the order total. As with a full refund, the Stripe call
     * is issued BEFORE the DB write so a Stripe failure aborts the operation and
     * leaves the Order unchanged.
     *
     * Returns true when the refund was applied. An already-terminal Order (or
     * one with nothing left to refund) is a no-op and returns false without
     * calling Stripe.
     *
     * @throws \InvalidArgumentException when $amountMinor is not in 1..remaining
     */
    public function partialRefund(Order $order, int $amountMinor): bool
    {
        if ($amountMinor < 1) {
            throw new \InvalidArgumentException('Refund amount must be a positive number of minor units.');
        }

        // A partial refund only applies to a paid Order that still carries a
        // charge; anything else (free, reserved, already terminal) has no charge
        // to refund against.
        if ($order->status !== Order::STATUS_PAID || $order->stripe_charge_id === null) {
            return false;
        }

        if ($amountMinor > $order->refundableRemainingMinor()) {
            throw new \InvalidArgumentException('Refund amount exceeds the remaining refundable balance.');
        }

        $company = $order->company;

        // Issue the Stripe refund BEFORE touching the DB so a failure leaves the
        // Order (and its recorded refunded total) unchanged. (Requirement 17.2)
        $this->stripe->refundCharge(
            connectedAccountId: (string) $company->stripe_account_id,
            chargeId: (string) $order->stripe_charge_id,
            amountMinor: $amountMinor,
        );

        // Record the amount and, if it completes the total, flip to terminal —
        // all under a row lock so a concurrent refund/webhook cannot race past
        // the total or double-apply the terminal effects.
        return (bool) DB::transaction(function () use ($order, $amountMinor): bool {
            $locked = Order::withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || in_array($locked->status, self::TERMINAL_STATUSES, true)) {
                return false;
            }

            $locked->refunded_total_minor += $amountMinor;
            $locked->save();

            // Keep the caller's instance in step so audit/UI read fresh values.
            $order->refunded_total_minor = $locked->refunded_total_minor;

            // Cumulative refunds have reached the full total → the Order is now
            // fully refunded. Void its Tickets and return its capacity via the
            // shared terminal path (idempotent, converges with the webhook).
            if ($locked->refunded_total_minor >= $locked->order_total_minor) {
                $previousStatus = $locked->status;
                $locked->status = Order::STATUS_REFUNDED;
                $locked->save();
                $order->status = Order::STATUS_REFUNDED;

                Ticket::withoutGlobalScopes()
                    ->where('order_id', $locked->getKey())
                    ->update(['status' => Ticket::STATUS_VOIDED]);

                $this->returnCapacity($locked, $previousStatus);
            }

            return true;
        });
    }

    /**
     * Mark an Order refunded in reaction to a `charge.refunded` webhook: void
     * its Tickets and return its sold capacity. Never calls Stripe (the refund
     * already happened at Stripe). Idempotent, so it converges with a prior
     * dashboard refund of the same Order. (Requirement 17.4)
     */
    public function markRefundedFromWebhook(Order $order): bool
    {
        return $this->applyTerminal($order, Order::STATUS_REFUNDED);
    }

    /**
     * Mark an Order disputed in reaction to a `charge.dispute.created` webhook:
     * void its Tickets and return its sold capacity. Idempotent. (Requirement
     * 17.5)
     */
    public function markDisputedFromWebhook(Order $order): bool
    {
        return $this->applyTerminal($order, Order::STATUS_DISPUTED);
    }

    /**
     * Apply a terminal transition atomically and idempotently: under a row lock,
     * skip Orders already in a terminal state; otherwise set the new status,
     * void the Tickets, and return the held capacity. Returns true when this
     * call performed the transition, false when it was skipped.
     */
    private function applyTerminal(Order $order, string $terminalStatus): bool
    {
        $transitioned = DB::transaction(function () use ($order, $terminalStatus): ?string {
            $locked = Order::withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || in_array($locked->status, self::TERMINAL_STATUSES, true)) {
                // Gone, or already terminal → nothing to do. This is the
                // convergence point for the dashboard + webhook paths.
                return null;
            }

            // Capture the pre-transition status so we know whether the held
            // capacity is sold (confirmed) or still reserved.
            $previousStatus = $locked->status;

            $locked->status = $terminalStatus;
            $locked->save();

            Ticket::withoutGlobalScopes()
                ->where('order_id', $locked->getKey())
                ->update(['status' => Ticket::STATUS_VOIDED]);

            $this->returnCapacity($locked, $previousStatus);

            return $previousStatus;
        });

        return $transitioned !== null;
    }

    /**
     * Return the capacity an Order held, based on the status it was in before
     * the terminal transition. Confirmed Orders (paid/free-confirmed) had their
     * capacity committed to `sold_count`, so it is returned via releaseSold; a
     * reserved Order still holds it in `reserved_count`, so it is released.
     */
    private function returnCapacity(Order $order, string $previousStatus): void
    {
        $event = Event::withoutGlobalScopes()->find($order->event_id);

        if ($event === null) {
            return;
        }

        $quantities = $this->quantitiesFor($order);

        if ($quantities === []) {
            return;
        }

        if (in_array($previousStatus, [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED], true)) {
            // Confirmed Order: its capacity is in the sold bucket.
            $this->capacity->releaseSold($event, $quantities);

            return;
        }

        if ($previousStatus === Order::STATUS_RESERVED) {
            // Still-reserved Order cancelled before payment: return the hold.
            $this->capacity->release($event, $quantities);
        }
    }

    /**
     * The per-Ticket_Type quantities held by the Order, recovered from its
     * Ticket rows. Bypasses the tenant scope: this runs in service/webhook
     * context where no request Company is resolved.
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
