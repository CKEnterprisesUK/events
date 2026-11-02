<?php

namespace App\Services\Mail;

use App\Jobs\SendRefundEmailJob;
use App\Mail\RefundConfirmationMail;
use App\Models\Order;

/**
 * The Platform's boundary for sending refund confirmation emails. All refund
 * email delivery goes through this interface so the transport can be swapped
 * without touching callers: the default {@see SmtpRefundMailer} sends the
 * branded {@see RefundConfirmationMail} via the configured cPanel SMTP mailer,
 * and a future API-backed mailer can replace the container binding with no
 * change to {@see SendRefundEmailJob} or the dashboard controller. In automated
 * tests the {@see FakeRefundMailer} is bound so no real email is ever sent.
 */
interface RefundMailer
{
    /**
     * Send the branded refund confirmation email for a refunded Order.
     *
     * @param  int  $amountMinor  the amount refunded by this refund, in minor currency units.
     * @param  bool  $fullyRefunded  whether the order is now refunded in full (tickets voided) vs a standing partial refund.
     */
    public function sendRefund(Order $order, int $amountMinor, bool $fullyRefunded): void;
}
