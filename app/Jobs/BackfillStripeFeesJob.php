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
 * Backfills the ACTUAL Stripe processing fee (`orders.stripe_fee_minor`) for
 * paid Orders where it is still unknown, by reading each charge's balance
 * transaction from the Company's connected account. (Truthful-payout feature)
 *
 * ## Why this job exists
 *
 * The fee is captured opportunistically the moment a payment is confirmed (see
 * {@see WebhookProcessor::captureStripeFee()}). But Stripe does
 * not always have the charge's balance transaction ready at the instant the
 * `checkout.session.completed` webhook fires — the fee "might not be available"
 * until the charge has settled. When that first read comes back null the Order
 * is left with `stripe_fee_minor = NULL` on purpose, and this sweeper fills it
 * in on a later pass once the balance transaction exists. So every paid Order
 * converges on its true fee without ever recording a guessed value.
 *
 * ## What it selects
 *
 * Paid Orders that (a) are not free (there is a charge), (b) carry a
 * PaymentIntent id to look up, and (c) still have a NULL `stripe_fee_minor`.
 * Runs across all Companies (no tenant scope) since it executes in the
 * scheduler with no resolved Company. A per-run cap keeps a single invocation
 * bounded on shared hosting; the next run picks up the remainder.
 *
 * ## Idempotency
 *
 * Reading a fee mutates nothing on Stripe. An Order already carrying a fee is
 * excluded by the NULL filter, so a repeated run only ever fills gaps and never
 * overwrites a captured value. A still-unavailable fee simply leaves the row for
 * a future run.
 *
 * ## Scheduling
 *
 * Registered as `stripe:backfill-fees` in `routes/console.php`, run
 * SYNCHRONOUSLY from cron like the other sweepers (shared cPanel hosting
 * disables `proc_open`, so `schedule:run` cannot be used).
 */
class BackfillStripeFeesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Maximum Orders to attempt in a single run, so one invocation stays bounded
     * on shared hosting. Remaining gaps are picked up by the next run.
     */
    public const BATCH_LIMIT = 200;

    public function __construct(private readonly int $limit = self::BATCH_LIMIT) {}

    /**
     * Attempt to capture the missing Stripe fee for each eligible Order.
     *
     * @return int the number of Orders whose fee was captured this run.
     */
    public function handle(StripePaymentService $stripe): int
    {
        // The orders table is introduced in the checkout slice; before it exists
        // this is a safe no-op (mirrors the reservation sweeper's guard).
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $orders = Order::withoutGlobalScopes()
            ->where('status', Order::STATUS_PAID)
            ->whereNull('stripe_fee_minor')
            ->whereNotNull('stripe_payment_intent_id')
            ->where('order_total_minor', '>', 0)
            ->limit($this->limit)
            ->get();

        // Resolve the connected account per Company once, so a batch spanning
        // multiple Companies does not re-query the same Company repeatedly.
        $accountIdByCompany = [];
        $captured = 0;

        foreach ($orders as $order) {
            $companyId = (int) $order->company_id;

            if (! array_key_exists($companyId, $accountIdByCompany)) {
                $accountIdByCompany[$companyId] = Company::withoutGlobalScopes()
                    ->find($companyId)?->stripe_account_id;
            }

            $accountId = $accountIdByCompany[$companyId];

            if ($accountId === null || $accountId === '') {
                continue;
            }

            $fee = $stripe->retrieveChargeFee($accountId, (string) $order->stripe_payment_intent_id);

            if ($fee === null) {
                // Still not settled — leave it for a later run.
                continue;
            }

            $order->stripe_fee_minor = $fee->feeMinor;

            if ($order->stripe_charge_id === null && $fee->chargeId !== '') {
                $order->stripe_charge_id = $fee->chargeId;
            }

            $order->save();
            $captured++;
        }

        return $captured;
    }
}
