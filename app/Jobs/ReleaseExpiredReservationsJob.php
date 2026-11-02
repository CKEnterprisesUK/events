<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\CapacityReservationService;
use App\Services\OrderFulfilmentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeSessionStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Releases capacity held by reservations whose window has elapsed — but ONLY
 * once Stripe confirms the payment did not (and can no longer) succeed, so a
 * slow-but-successful payment never has its seat released out from under it.
 * (Requirements 10.7, 10.12; reservation-vs-payment race fix)
 *
 * ## Why this job exists
 *
 * {@see CapacityReservationService::reserve()} holds capacity by incrementing
 * `ticket_types.reserved_count` and stamps a `reserved_until` (now +
 * RESERVATION_WINDOW_SECONDS) on the Order. If the Customer never completes
 * payment (abandons checkout, closes the tab) the hold would otherwise linger
 * forever and slowly starve the Event of availability. This scheduled job is
 * the sweeper that finds those stale holds and hands them back.
 *
 * ## The race it must not lose
 *
 * A paid Order is only confirmed by the `checkout.session.completed` webhook,
 * which can arrive LATE (bank 3-D Secure delays, Stripe processing lag) — after
 * the reservation window has elapsed. A naive sweeper that released on the
 * window alone would hand the seat away and void the tickets, then the late
 * webhook (which only acts on a still-`reserved` Order) would no-op: money in,
 * no tickets. To prevent this the sweeper NEVER releases a paid Order's seat on
 * the timer alone — for any Order carrying a Stripe session it first asks
 * Stripe for the Session status and only releases when Stripe positively
 * reports the Session expired/unpaid. A Session Stripe reports PAID is confirmed
 * here instead (the sweeper doubles as a webhook backstop); anything it cannot
 * confirm (still open, or Stripe unreachable) keeps the hold — a held seat is
 * recoverable, an oversold one is not. The window is also aligned with the
 * Session's `expires_at`, so in normal operation the seat is simply held for as
 * long as Stripe will take the money.
 *
 * ## Scheduling
 *
 * Registered to run every minute in `routes/console.php`, in line with the
 * cron-drained-queue hosting model: the same per-minute `schedule:run` that
 * drains the DB queue also sweeps expired reservations, so no persistent worker
 * is required. Running frequently keeps the released-capacity latency close to
 * the 900s window. (Design → Jobs (DB Queue), Hosting and Deployment Notes)
 *
 * ## Idempotency
 *
 * The release path is idempotent end to end:
 *   - {@see CapacityReservationService::release()} clamps `reserved_count` at 0,
 *     so releasing the same hold twice never drives the count negative.
 *   - Each expired Order is flipped to `expired` inside the same transaction
 *     that reads it `FOR UPDATE`, so a second overlapping run (or a retried
 *     job) sees no `reserved` rows for it and does nothing.
 * Concurrent or repeated runs therefore converge to the same state. (10.7)
 *
 * ## Reserved_until / orders wiring
 *
 * The authoritative source of expired reservations is the `orders` table
 * (`status = 'reserved'` AND `reserved_until < now`), joined to its `tickets`
 * to recover the per-Ticket_Type quantities to release. Those tables are
 * created by task 12.2 (CheckoutController + orders/tickets migrations). The
 * {@see reservationSourceAvailable()} guard remains as a harmless safety net so
 * the sweep no-ops rather than errors if it ever runs before the schema exists
 * (e.g. a partial migration state); in normal operation the tables are present.
 */
class ReleaseExpiredReservationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Sweep every expired reservation and release its held capacity.
     *
     * Groups the expired holds by Event so each Event's Ticket_Types are
     * released in a single {@see CapacityReservationService::release()} call
     * (one locked transaction per Event), then marks the swept Orders expired.
     */
    public function handle(
        CapacityReservationService $capacity,
        OrderFulfilmentService $fulfilment,
        StripePaymentService $stripe,
    ): void {
        // The orders/tickets tables are introduced in task 12.2. Until they
        // exist there is no reservation source, so the sweep is a safe no-op.
        // (Documented above: reserved_until/orders wiring completes in 12.x.)
        if (! $this->reservationSourceAvailable()) {
            return;
        }

        // Sweep per-Order, not in a single bulk update: each paid-capable Order
        // must be checked against Stripe BEFORE its seat is released, and the
        // outcome differs per Order (release / keep-held / confirm). We still
        // handle one Order per transaction so a concurrent run can't double-act.
        foreach ($this->expiredOrderIds() as $orderId) {
            $this->sweepOrder($capacity, $fulfilment, $stripe, $orderId);
        }
    }

    /**
     * Sweep one expired Order. Re-reads it `FOR UPDATE` so a concurrent/retried
     * run that already handled it finds it no longer `reserved` and skips.
     *
     * Decision, for an Order whose reservation window has elapsed:
     *   - A FREE order (no Stripe session) → release + expire, as before.
     *   - A paid order WITH a Stripe session → ask Stripe for the Session
     *     status FIRST:
     *       · paid     → DO NOT release; confirm the Order (webhook may have
     *                    been missed). Seat stays sold.
     *       · expired  → safe to release + expire/void.
     *       · open     → still payable; keep the hold (do nothing this pass).
     *       · unknown  → Stripe unreachable/ambiguous → FAIL SAFE, keep the
     *                    hold. A held seat is recoverable; an oversold one is not.
     * (Reservation-vs-payment race fix)
     */
    protected function sweepOrder(
        CapacityReservationService $capacity,
        OrderFulfilmentService $fulfilment,
        StripePaymentService $stripe,
        int $orderId,
    ): void {
        // Resolve the Stripe decision for a paid order OUTSIDE the DB
        // transaction — the Stripe call can be slow and must not hold a row lock.
        $order = Order::withoutGlobalScopes()->find($orderId);

        if ($order === null || $order->status !== Order::STATUS_RESERVED) {
            return;
        }

        $sessionStatus = null;

        if ($order->stripe_session_id !== null && $order->stripe_session_id !== '') {
            $company = Company::withoutGlobalScopes()->find($order->company_id);
            $accountId = $company?->stripe_account_id;

            if ($accountId === null || $accountId === '') {
                // A paid order with a session but no resolvable connected
                // account: we cannot verify payment, so fail safe and keep the
                // hold rather than risk releasing a seat that was paid for.
                Log::warning('Expired reservation has a Stripe session but no connected account; keeping hold (fail-safe).', [
                    'order_reference' => $order->order_reference,
                ]);

                return;
            }

            $sessionStatus = $stripe->retrieveCheckoutSessionStatus($accountId, $order->stripe_session_id);

            // Paid at Stripe but we lost the race: confirm instead of release.
            if ($sessionStatus->isPaid()) {
                $this->confirmPaidOrder($capacity, $fulfilment, $order, $sessionStatus);

                return;
            }

            // Anything other than a positively-expired Session => keep holding.
            if (! $sessionStatus->isSafeToRelease()) {
                return;
            }
        }

        // Either a free/no-session order, or Stripe positively confirmed the
        // Session is expired: release the hold and mark the Order expired.
        $this->releaseAndExpire($capacity, $orderId);
    }

    /**
     * Release one expired Order's hold and flip it to `expired`, voiding its
     * Tickets. Re-reads `FOR UPDATE` inside the transaction so a concurrent run
     * that already swept it is a no-op. (Requirement 10.7)
     */
    protected function releaseAndExpire(CapacityReservationService $capacity, int $orderId): void
    {
        DB::transaction(function () use ($capacity, $orderId): void {
            $locked = DB::table('orders')
                ->where('id', $orderId)
                ->where('status', 'reserved')
                ->where('reserved_until', '<', now())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return;
            }

            $event = Event::withoutGlobalScopes()->find((int) $locked->event_id);

            if ($event === null) {
                return;
            }

            $capacity->release($event, $this->quantitiesForOrder($orderId));

            DB::table('orders')
                ->where('id', $orderId)
                ->update(['status' => 'expired', 'updated_at' => now()]);

            Ticket::withoutGlobalScopes()
                ->where('order_id', $orderId)
                ->update(['status' => Ticket::STATUS_VOIDED]);
        });
    }

    /**
     * Confirm an expired-window Order that Stripe reports PAID: mark it paid,
     * capture the PaymentIntent, and hand it to fulfilment (commit capacity,
     * QR, ticket email). This is the sweeper winning back a webhook we appear to
     * have missed — payment is authoritative. The seat was still HELD (we only
     * reach here because the Order is still `reserved`), so fulfilment commits
     * the existing hold normally with no oversell risk.
     */
    protected function confirmPaidOrder(
        CapacityReservationService $capacity,
        OrderFulfilmentService $fulfilment,
        Order $order,
        StripeSessionStatus $sessionStatus,
    ): void {
        $justPaid = DB::transaction(function () use ($order, $sessionStatus): bool {
            $locked = Order::withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== Order::STATUS_RESERVED) {
                return false;
            }

            $locked->status = Order::STATUS_PAID;

            if ($sessionStatus->paymentIntentId !== null && $locked->stripe_payment_intent_id === null) {
                $locked->stripe_payment_intent_id = $sessionStatus->paymentIntentId;
            }

            $locked->save();

            return true;
        });

        if ($justPaid) {
            Log::info('Sweeper confirmed a paid order whose webhook was missed.', [
                'order_reference' => $order->order_reference,
            ]);

            $fulfilment->fulfil($order->refresh());
        }
    }

    /**
     * Per-Ticket_Type quantities held by one Order, recovered from its tickets.
     *
     * @return array<int, int>
     */
    protected function quantitiesForOrder(int $orderId): array
    {
        return DB::table('tickets')
            ->where('order_id', $orderId)
            ->groupBy('ticket_type_id')
            ->selectRaw('ticket_type_id, COUNT(*) as qty')
            ->pluck('qty', 'ticket_type_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }

    /**
     * Ids of every Order still `reserved` whose window has elapsed, across all
     * Companies (the sweeper runs with no resolved tenant). Each is then handled
     * individually by {@see sweepOrder()}.
     *
     * @return array<int, int>
     */
    protected function expiredOrderIds(): array
    {
        return DB::table('orders')
            ->where('status', 'reserved')
            ->where('reserved_until', '<', now())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether the reservation source tables exist yet. They are created in task
     * 12.2; before that the sweep no-ops. (See class docblock.)
     */
    protected function reservationSourceAvailable(): bool
    {
        return Schema::hasTable('orders') && Schema::hasTable('tickets');
    }
}
