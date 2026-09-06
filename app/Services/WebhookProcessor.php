<?php

namespace App\Services;

use App\Jobs\ProcessWebhookJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Order;
use App\Models\ProcessedWebhook;
use App\Services\Stripe\StripeWebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
     * Mark the Order matching the completed Checkout Session paid — but only if
     * it is still reserved, so a redelivery leaves an already-paid Order (and
     * its money) untouched and triggers no additional charge. (Requirements
     * 12.6, 12.7, 19.3)
     */
    private function handleCheckoutSessionCompleted(StripeWebhookEvent $event): void
    {
        $order = $this->resolveOrder($event);

        if ($order === null) {
            return;
        }

        $justPaid = DB::transaction(function () use ($order): bool {
            $locked = Order::withoutGlobalScopes()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->status !== Order::STATUS_RESERVED) {
                return false;
            }

            $locked->status = Order::STATUS_PAID;
            $locked->save();

            return true;
        });

        // Fulfil the now-paid Order: commit capacity reserved → sold, generate
        // the QR, and enqueue the ticket email. Only the transition that
        // actually marked the Order paid triggers fulfilment, and fulfilment is
        // itself idempotent — a redelivered event therefore neither re-charges
        // nor re-fulfils. (Requirements 14.1, 14.3)
        if ($justPaid) {
            $this->fulfilment->fulfil($order->refresh());

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
     * Refresh a Company's charges-enabled flag from an account-capability
     * update so enabling charges turns on paid ticket sales. (Requirement 11.4)
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

        $chargesEnabled = (bool) ($event->data['charges_enabled'] ?? false);

        $company->stripe_charges_enabled = $chargesEnabled;
        $company->save();
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
