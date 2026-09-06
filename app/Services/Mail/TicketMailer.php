<?php

namespace App\Services\Mail;

use App\Jobs\SendTicketEmailJob;
use App\Models\Order;
use App\Services\OrderFulfilmentService;
use App\Services\QrService;

/**
 * The Platform's boundary for sending ticket emails. All ticket-email delivery
 * goes through this interface so the transport can be swapped without touching
 * callers: the default {@see SmtpTicketMailer} sends via the configured cPanel
 * SMTP mailer, and a future `ApiTicketMailer` (a transactional-email API) can
 * replace the container binding with no change to {@see SendTicketEmailJob}
 * or {@see OrderFulfilmentService}. In automated tests the
 * {@see FakeTicketMailer} is bound so no real email is ever sent. (Requirements
 * 14.4, 14.5; design → MailService abstraction)
 */
interface TicketMailer
{
    /**
     * Send the branded ticket email for a confirmed Order to its Customer.
     *
     * The `$qrPayload` is the scannable value produced by
     * {@see QrService::payloadFor()} — the QR encoding
     * `HMAC(secret, Order_Reference)` that the attendee presents at entry. The
     * email displays the Company/Event branding and the customisable ticket
     * information fields alongside the QR. (Requirements 14.1, 14.4, 14.6)
     */
    public function sendTicket(Order $order, string $qrPayload): void;
}
