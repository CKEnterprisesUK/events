<?php

namespace App\Jobs;

use App\Models\Order;
use App\Services\Mail\TicketMailer;
use App\Services\OrderFulfilmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends the branded ticket email for a confirmed Order off the DB queue, so
 * {@see OrderFulfilmentService} can enqueue it during confirmation
 * and the actual send happens when the cron-drained queue runs
 * (`queue:work --stop-when-empty`). (Requirements 14.3, 14.4, 15.1)
 *
 * ## What it carries
 *
 * The job carries the Order id and the scannable QR payload
 * (`HMAC(secret, Order_Reference)`), not the whole Order model, so it stays a
 * small serialisable value. The QR payload is a pure function of the stable
 * Order_Reference, so carrying it (or re-deriving it) yields the same code.
 *
 * ## Retry safety
 *
 * Delivery is delegated to the {@see TicketMailer} abstraction (cPanel SMTP in
 * production, a fake in tests). A transport failure lets the job retry; a
 * persistently failing job lands in `failed_jobs` for inspection rather than
 * losing the ticket email. The Order is re-read across the whole Platform (no
 * resolved tenant on the queue) and a since-deleted Order is a safe no-op.
 * (Requirements 15.4; design → Queue / mail errors)
 */
class SendTicketEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int $orderId,
        private readonly string $qrPayload,
    ) {}

    public function handle(TicketMailer $mailer): void
    {
        $order = Order::withoutGlobalScopes()->find($this->orderId);

        if ($order === null) {
            // The Order was removed before the queue drained; nothing to send.
            return;
        }

        $mailer->sendTicket($order, $this->qrPayload);
    }
}
