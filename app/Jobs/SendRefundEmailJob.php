<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Mail\RefundMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the branded refund confirmation email for a refunded Order off the DB
 * queue, so the dashboard refund action can enqueue it and the actual send
 * happens when the cron-drained queue runs (`queue:work --stop-when-empty`).
 * Mirrors {@see SendTicketEmailJob}.
 *
 * ## What it carries
 *
 * The job carries the Order id, the amount refunded (minor units) and whether
 * the order is now fully refunded — small serialisable values, not the whole
 * Order model. The amount is captured at dispatch time because a later refund
 * on the same order would change the Order's cumulative totals.
 *
 * ## Retry safety
 *
 * Delivery is delegated to the {@see RefundMailer} abstraction (cPanel SMTP in
 * production, a fake in tests). A transport failure lets the job retry; a
 * persistently failing job lands in `failed_jobs` for inspection rather than
 * silently losing the refund email. The Order is re-read across the whole
 * Platform (no resolved tenant on the queue) and a since-deleted Order is a
 * safe no-op.
 */
class SendRefundEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $orderId,
        private readonly int $amountMinor,
        private readonly bool $fullyRefunded,
    ) {}

    public function handle(RefundMailer $mailer): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);

        if ($order === null) {
            // The Order was removed before the queue drained; nothing to send.
            return;
        }

        $mailer->sendRefund($order, $this->amountMinor, $this->fullyRefunded);
    }
}
