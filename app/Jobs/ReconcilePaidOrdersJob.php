<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\Order;
use App\Services\Stripe\StripePaymentService;
use App\Services\WebhookProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles Orders against Stripe to recover any whose payment SUCCEEDED but
 * which the Platform never confirmed — the money-in/no-tickets case. For each
 * candidate Order it reads the Checkout Session status from Stripe and, when
 * Stripe reports the Session PAID, drives it through the SAME authoritative
 * confirmation path the webhook uses ({@see WebhookProcessor::markPaid()} +
 * {@see WebhookProcessor::finishConfirmation()}): mark paid, un-void tickets,
 * re-commit capacity (honouring the seats), generate the QR, and send the
 * ticket email. (Reservation-vs-payment race fix)
 *
 * ## Why this job exists
 *
 * Before the hold window was aligned with the Stripe payment window and the
 * sweeper taught to check Stripe before releasing, a slow-but-successful
 * payment could land AFTER its reservation expired. The webhook (which only
 * acted on a still-`reserved` Order) then no-op'd, leaving a succeeded charge
 * with an `expired` Order and `voided` tickets. This job is both the one-off
 * recovery tool for such Orders (run with an explicit `order_reference`) and a
 * standing safety net (run with no argument to sweep all unconfirmed
 * paid-capable Orders) in case a webhook is ever missed again.
 *
 * ## What it selects
 *
 * - With an `order_reference`: exactly that Order, whatever its status
 *   (terminal statuses are still safe — the confirmation path no-ops anything
 *   already paid/refunded).
 * - Without one: Orders in `reserved` or `expired` status that carry a Stripe
 *   session id (a paid-capable checkout), across all Companies (no tenant
 *   scope), bounded per run.
 *
 * ## Idempotency & safety
 *
 * Reading a Session mutates nothing on Stripe. Confirmation is guarded by
 * {@see WebhookProcessor::markPaid()}, which only flips a `reserved`/`expired`
 * Order and leaves an already-paid one untouched, so a repeated run never
 * double-confirms, double-commits capacity, or double-sends the email. An Order
 * Stripe does NOT report as paid is left exactly as it was.
 */
class ReconcilePaidOrdersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Maximum Orders to attempt in a single no-argument sweep. */
    public const BATCH_LIMIT = 200;

    /**
     * Per-run summary, read back by the console command.
     *
     * @var array{examined: int, recovered: int, skipped: int}
     */
    public array $summary = ['examined' => 0, 'recovered' => 0, 'skipped' => 0];

    public function __construct(
        private readonly ?string $orderReference = null,
        private readonly int $limit = self::BATCH_LIMIT,
    ) {}

    public function handle(StripePaymentService $stripe, WebhookProcessor $webhook): void
    {
        if (! Schema::hasTable('orders')) {
            return;
        }

        foreach ($this->candidates() as $order) {
            $this->summary['examined']++;

            $accountId = $this->connectedAccountId($order);

            if ($order->stripe_session_id === null || $order->stripe_session_id === '' || $accountId === null) {
                $this->summary['skipped']++;

                continue;
            }

            $status = $stripe->retrieveCheckoutSessionStatus($accountId, $order->stripe_session_id);

            if (! $status->isPaid()) {
                // Stripe does not confirm a successful payment — leave the Order
                // untouched. Fail safe: never confirm on an unconfirmed payment.
                $this->summary['skipped']++;

                continue;
            }

            $transition = $webhook->markPaid($order, $status->paymentIntentId);

            if ($transition !== null) {
                $webhook->finishConfirmation($order, $transition);
                $this->summary['recovered']++;
            } else {
                // Already paid (or no longer confirmable) — nothing to recover.
                $this->summary['skipped']++;
            }
        }
    }

    /**
     * The Orders to examine: a single named Order, or all unconfirmed
     * paid-capable Orders across the Platform.
     *
     * @return iterable<int, Order>
     */
    private function candidates(): iterable
    {
        $query = Order::withoutGlobalScopes();

        if ($this->orderReference !== null) {
            return $query->where('order_reference', $this->orderReference)->get();
        }

        return $query
            ->whereIn('status', [Order::STATUS_RESERVED, Order::STATUS_EXPIRED])
            ->whereNotNull('stripe_session_id')
            ->orderBy('id')
            ->limit($this->limit)
            ->get();
    }

    private function connectedAccountId(Order $order): ?string
    {
        $accountId = Company::withoutGlobalScopes()
            ->find($order->company_id)?->stripe_account_id;

        return ($accountId === null || $accountId === '') ? null : $accountId;
    }
}
