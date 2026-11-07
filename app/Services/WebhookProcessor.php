<?php

namespace App\Services;

use App\Jobs\ProcessWebhookJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Models\Ticket;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Idempotent handler for verified Stripe webhook events, keyed on the Stripe
 * event id. It is the single place heavy webhook work is dispatched from, run
 * off the DB queue by {@see ProcessWebhookJob}. (Requirements 12.6,
 * 12.7, 19.3, 19.5)
 *
 * ## Idempotency
 *
 * Before doing any work {@see process()} claims the event id by inserting a
 * `processed_webhooks` row. The UNIQUE index on `stripe_event_id` means the
 * claim succeeds exactly once; a redelivery of the same event id collides and
 * is skipped without side effects. So `checkout.session.completed` marks an
 * Order paid at most once and creates no additional charge on redelivery.
 * (Requirements 12.6, 12.7, 19.3)
 *
 * ## Dispatch
 *
 * A verified event is routed by type to a handler:
 *   - `checkout.session.completed` → mark the matching reserved Order paid.
 *   - `charge.refunded`            → mark the Order refunded and void its Tickets.
 *   - `charge.dispute.created`     → mark the Order disputed.
 *   - `account.updated`            → refresh the Company's charges-enabled flag.
 * Unrecognised types are accepted and recorded (so Stripe stops retrying) but
 * do nothing. (Requirement 19.5)
 */
class WebhookProcessor
{
    public function __construct(
        private readonly OrderFulfilmentService $fulfilment,
        private readonly OrderCancellationService $cancellation,
        private readonly AuditLogger $audit,
        private readonly StripePaymentService $stripe,
        private readonly CapacityReservationService $capacity,
    ) {}

    /**
     * Process a verified webhook event exactly once.
     *
     * Returns true when this call actually processed the event, false when it
     * was a duplicate (already recorded) and therefore skipped. All Company-
     * owned lookups bypass the tenant scope: the webhook endpoint resolves no
     * Company, so handlers locate rows across the whole Platform by their Stripe
     * identifiers.
     */
    public function process(StripeWebhookEvent $event): bool
    {
        if (! $this->claim($event)) {
            // A concurrent/previous delivery already claimed this event id.
            return false;
        }

        match ($event->type) {
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event),
            'charge.refunded' => $this->handleChargeRefunded($event),
            'charge.dispute.created' => $this->handleDisputeCreated($event),
            'account.updated' => $this->handleAccountUpdated($event),
            default => null,
        };

        return true;
    }

    /**
     * Atomically claim the event id by inserting its `processed_webhooks` row.
     * The UNIQUE key on `stripe_event_id` rejects a second insert, so this
     * returns true exactly once per event id. (Requirement 19.3)
     */
    private function claim(StripeWebhookEvent $event): bool
    {
        // An empty event id cannot be deduped safely; treat it as unprocessable
        // rather than claiming a blank key that would swallow every such event.
        if ($event->id === '') {
            return false;
        }

        try {
            ProcessedWebhook::create([
                'stripe_event_id' => $event->id,
                'type' => $event->type,
                'processed_at' => now(),
            ]);
        } catch (QueryException $e) {
            // Unique-constraint violation → already processed. Any other error
            // propagates so the job retries. (Requirement 19.3)
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Mark the Order matching the completed Checkout Session paid, then fulfil
     * it. Payment is authoritative: the Order is confirmed when it is still
     * `reserved` (normal path) OR was swept to `expired` before this webhook
     * arrived (recovery path — see {@see markPaid()}). Any already-terminal
     * Order is left untouched, so a redelivery leaves an already-paid Order (and
     * its money) alone and triggers no additional charge. (Requirements 12.6,
     * 12.7, 19.3; reservation-vs-payment race fix)
     */
    private function handleCheckoutSessionCompleted(StripeWebhookEvent $event): void
    {
        $order = $this->resolveOrder($event);

        if ($order === null) {
            return;
        }

        // The completed Checkout Session carries the PaymentIntent id; capture
        // it onto the Order (if not already set) so the actual Stripe fee can be
        // read from its charge's balance transaction, and so refunds/lookups can
        // resolve the Order by PaymentIntent later.
        $paymentIntentId = $this->stringField($event->data, 'payment_intent');

        $transition = $this->markPaid($order, $paymentIntentId);

        $this->finishConfirmation($order, $transition);
    }

    /**
     * Flip an Order to `paid` when it is still `reserved` (normal path — seat
     * held) or was swept to `expired` before confirmation arrived (recovery
     * path — seat released, tickets voided). Payment is authoritative, so both
     * are accepted; any already-terminal status is left untouched (idempotent).
     * On the recovery path the Order's tickets are un-voided in the same
     * transaction. Returns the PRIOR status (`reserved`/`expired`) when it
     * flipped, or null when nothing changed. Shared by the webhook and the
     * reconcile command. (Requirements 12.6, 12.7, 19.3; reservation-vs-payment race fix)
     */
    public function markPaid(Order $order, ?string $paymentIntentId): ?string
    {
        return DB::transaction(function () use ($order, $paymentIntentId): ?string {
            $locked = Order::withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            $from = $locked->status;

            if ($from !== Order::STATUS_RESERVED && $from !== Order::STATUS_EXPIRED) {
                // Already paid/refunded/etc. — nothing to do (idempotent).
                return null;
            }

            $locked->status = Order::STATUS_PAID;

            if ($paymentIntentId !== null && $locked->stripe_payment_intent_id === null) {
                $locked->stripe_payment_intent_id = $paymentIntentId;
            }

            $locked->save();

            // Recovery path: the sweep had already voided this Order's tickets
            // when it expired, so restore them to valid now the payment is
            // confirmed. (Un-voiding a still-reserved order's tickets is a
            // harmless no-op — they were never voided.)
            if ($from === Order::STATUS_EXPIRED) {
                Ticket::withoutGlobalScopes()
                    ->where('order_id', $locked->getKey())
                    ->update(['status' => Ticket::STATUS_VALID]);
            }

            return $from;
        });
    }

    /**
     * Complete a paid-Order confirmation after its status has been flipped to
     * `paid`: for the expired-recovery path re-commit capacity and honour the
     * seats, then fulfil (QR + ticket email), capture the Stripe fee, and record
     * the audit event. Shared by the webhook and the reconcile command so both
     * converge on exactly one confirmation path. A null `$transition` (nothing
     * flipped — already paid, or gone) is a no-op. (Reservation-vs-payment race fix)
     *
     * @param  string|null  $transition  The status the Order was in BEFORE this
     *                                   confirmation (`reserved` or `expired`),
     *                                   or null if no transition happened.
     */
    public function finishConfirmation(Order $order, ?string $transition): void
    {
        $justPaid = $transition !== null;
        $wasExpired = $transition === Order::STATUS_EXPIRED;

        if ($justPaid) {
            $order = $order->refresh();

            // Recovery path only: the expired sweep already RELEASED this
            // Order's hold (reserved_count was decremented), so the normal
            // reserved→sold commit inside fulfil() would move nothing and leave
            // sold_count short. Add the units straight to sold_count here. This
            // honours the paid Order even if the seats were resold in the gap;
            // commitSoldDirect reports when that tips the Event over capacity so
            // we can alert rather than oversell silently. (Reservation-vs-payment race fix)
            if ($wasExpired) {
                $event = Event::withoutGlobalScopes()->find($order->event_id);

                if ($event !== null) {
                    $oversold = $this->capacity->commitSoldDirect($event, $this->quantitiesFor($order));

                    if ($oversold) {
                        Log::warning('Recovered a paid order after its hold expired, committing OVER capacity (honoured). Review event capacity.', [
                            'order_reference' => $order->order_reference,
                            'event_id' => $order->event_id,
                        ]);
                    }
                }
            }

            // In the recovery path the units were already added to sold_count
            // above, so fulfilment must not run the reserved → sold commit too:
            // it would pull other Customers' live holds into sold_count.
            $this->fulfilment->fulfil($order, capacityAlreadyCommitted: $wasExpired);

            // Capture the ACTUAL card-processing fee Stripe took on the connected
            // account, so the dashboard and reports can show a truthful net
            // payout rather than one that ignores Stripe's cut. This is a
            // best-effort read: a free order has nothing to charge, and Stripe
            // may not have settled the balance transaction at the instant this
            // webhook fires, so a null result simply leaves stripe_fee_minor
            // unset for a later delivery/backfill to fill in. It never blocks
            // fulfilment or the audit trail. (Truthful-payout feature)
            $this->captureStripeFee($order);

            // System event (no acting user): record the confirmed payment
            // against the Order's own Company for the trail.
            $this->audit->recordSystem(
                action: AuditLog::WEBHOOK_PAYMENT_CONFIRMED,
                auditable: $order,
                summary: 'Payment confirmed for order '.$order->order_reference,
                context: [
                    'order_reference' => $order->order_reference,
                    'amount_minor' => $order->order_total_minor,
                ],
            );
        }
    }

    /**
     * The per-Ticket_Type quantities an Order holds, recovered from its Ticket
     * rows. Bypasses the tenant scope — the webhook resolves no Company — and is
     * used by the expired-recovery path to know how many units of each type to
     * re-commit to `sold_count`. (Reservation-vs-payment race fix)
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

    /**
     * Best-effort capture of the actual Stripe processing fee for a just-paid
     * Order, read from the charge's balance transaction on the Company's
     * connected account. Skipped for free Orders (no charge) and for Orders with
     * no PaymentIntent or no connected account resolvable. A null fee (Stripe not
     * settled yet, or fee unavailable) leaves `stripe_fee_minor` unset so a later
     * delivery or a backfill can populate it — it is never treated as zero.
     * Persists the resolved charge id alongside the fee when the Order does not
     * already carry one. (Truthful-payout feature)
     */
    private function captureStripeFee(Order $order): void
    {
        // Free/zero orders never hit Stripe, so there is no processing fee.
        if ($order->order_total_minor === 0) {
            return;
        }

        if ($order->stripe_payment_intent_id === null) {
            return;
        }

        // The charge lives on the Company's connected account (direct charge),
        // so the fee must be read with that account in the Stripe-Account header.
        $company = Company::withoutGlobalScopes()->find($order->company_id);
        $accountId = $company?->stripe_account_id;

        if ($accountId === null || $accountId === '') {
            return;
        }

        $fee = $this->stripe->retrieveChargeFee($accountId, $order->stripe_payment_intent_id);

        if ($fee === null) {
            return;
        }

        $order->stripe_fee_minor = $fee->feeMinor;

        if ($order->stripe_charge_id === null && $fee->chargeId !== '') {
            $order->stripe_charge_id = $fee->chargeId;
        }

        $order->save();
    }

    /**
     * Mark the Order refunded and void its Tickets so the QR fails at scan,
     * returning its capacity. Delegates to {@see OrderCancellationService} — the
     * same idempotent path the Admin dashboard refund uses — so a dashboard
     * refund and this webhook converge on one refunded Order without
     * double-applying the void or the capacity return. (Requirement 17.4)
     */
    private function handleChargeRefunded(StripeWebhookEvent $event): void
    {
        $order = $this->resolveOrder($event);

        if ($order === null) {
            return;
        }

        // Log only when this delivery actually performed the refund transition,
        // so a redelivery (or convergence with a prior dashboard refund) records
        // nothing.
        if ($this->cancellation->markRefundedFromWebhook($order)) {
            $this->audit->recordSystem(
                action: AuditLog::WEBHOOK_REFUND_PROCESSED,
                auditable: $order,
                summary: 'Refund processed for order '.$order->order_reference,
                context: ['order_reference' => $order->order_reference],
            );
        }
    }

    /**
     * Mark the Order disputed to reflect the dispute, voiding its Tickets and
     * returning its capacity via the shared idempotent path. (Requirement 17.5)
     */
    private function handleDisputeCreated(StripeWebhookEvent $event): void
    {
        $order = $this->resolveOrder($event);

        if ($order === null) {
            return;
        }

        if ($this->cancellation->markDisputedFromWebhook($order)) {
            $this->audit->recordSystem(
                action: AuditLog::WEBHOOK_DISPUTE_CREATED,
                auditable: $order,
                summary: 'Dispute opened on order '.$order->order_reference,
                context: ['order_reference' => $order->order_reference],
            );
        }
    }

    /**
     * Refresh a Company's connected-account state from an `account.updated`
     * event so verification progress syncs automatically — without the Owner
     * having to revisit the onboarding return URL. Enabling charges turns on
     * paid ticket sales, and the outstanding requirements / disabled reason are
     * captured so the Payments page shows the Company exactly what Stripe is
     * still waiting on (e.g. once a business verification document clears review).
     * The `account.updated` event carries the full Account object, so these
     * fields are read straight off the event payload. (Requirements 11.3, 11.4)
     */
    private function handleAccountUpdated(StripeWebhookEvent $event): void
    {
        $accountId = $this->stringField($event->data, 'id');

        if ($accountId === null) {
            return;
        }

        $company = Company::query()
            ->where('stripe_account_id', $accountId)
            ->first();

        if ($company === null) {
            return;
        }

        $data = $event->data;
        $requirements = is_array($data['requirements'] ?? null) ? $data['requirements'] : [];

        $company->stripe_charges_enabled = (bool) ($data['charges_enabled'] ?? false);
        $company->stripe_payouts_enabled = (bool) ($data['payouts_enabled'] ?? false);
        $company->stripe_details_submitted = (bool) ($data['details_submitted'] ?? false);
        $company->stripe_disabled_reason = $this->stringField($requirements, 'disabled_reason');
        $company->stripe_requirements = [
            'currently_due' => $this->stringListField($requirements, 'currently_due'),
            'past_due' => $this->stringListField($requirements, 'past_due'),
            'pending_verification' => $this->stringListField($requirements, 'pending_verification'),
            'errors' => $this->requirementErrorsField($requirements),
        ];
        $company->save();
    }

    /**
     * Read a list-of-strings requirements field (e.g. `currently_due`) from a
     * requirements payload, keeping only non-empty string ids.
     *
     * @param  array<string, mixed>  $requirements
     * @return list<string>
     */
    private function stringListField(array $requirements, string $key): array
    {
        $value = $requirements[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($item): bool => is_string($item) && $item !== '',
        ));
    }

    /**
     * Normalise Stripe's requirement `errors` from the event payload into a
     * plain list of `{requirement, code, reason}` maps for storage/display.
     *
     * @param  array<string, mixed>  $requirements
     * @return list<array{requirement: string, code: string, reason: string}>
     */
    private function requirementErrorsField(array $requirements): array
    {
        $errors = $requirements['errors'] ?? null;

        if (! is_array($errors)) {
            return [];
        }

        $out = [];
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $out[] = [
                'requirement' => (string) ($error['requirement'] ?? ''),
                'code' => (string) ($error['code'] ?? ''),
                'reason' => (string) ($error['reason'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Locate the Order a charge/checkout event refers to, across all Companies
     * (the webhook endpoint has no resolved tenant). Matching prefers the Stripe
     * Checkout Session id, then the PaymentIntent id, then order metadata.
     */
    private function resolveOrder(StripeWebhookEvent $event): ?Order
    {
        $data = $event->data;

        $sessionId = $this->stringField($data, 'id');
        $paymentIntentId = $this->stringField($data, 'payment_intent');
        $orderReference = $this->stringField($data['metadata'] ?? [], 'order_reference');

        $query = Order::withoutGlobalScopes();

        if ($sessionId !== null) {
            $match = (clone $query)->where('stripe_session_id', $sessionId)->first();

            if ($match !== null) {
                return $match;
            }
        }

        if ($paymentIntentId !== null) {
            $match = (clone $query)->where('stripe_payment_intent_id', $paymentIntentId)->first();

            if ($match !== null) {
                return $match;
            }
        }

        if ($orderReference !== null) {
            return (clone $query)->where('order_reference', $orderReference)->first();
        }

        return null;
    }

    /**
     * Read a string field from a payload array, or null when absent/blank.
     *
     * @param  array<string, mixed>  $data
     */
    private function stringField(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Whether a QueryException is a unique-constraint violation (SQLSTATE 23000).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
