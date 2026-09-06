<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\EffectiveBranding;
use App\Services\Mail\SmtpTicketMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The branded ticket email for a confirmed Order, carrying the Order's QR and
 * the Order breakdown. Built by {@see SmtpTicketMailer} and
 * rendered from the `emails.ticket` view. (Requirements 14.1, 14.4, 14.6)
 *
 * The email shows the effective Company/Event branding (logo, primary colour)
 * and the customisable ticket information fields, resolved via
 * {@see BrandingResolver} so an Event-level override wins over the Company
 * default. The single QR_Code — a rendered PNG image of the scannable
 * `{Order_Reference}.HMAC(secret, Order_Reference)` payload — is embedded inline
 * (as a CID attachment) so the attendee can present it at entry; the raw
 * payload is also carried as machine-readable text for clients that block
 * images. (Requirements 14.1, 14.2, 14.6)
 */
class TicketMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{ticket_type: string, quantity: int}>  $lineItems
     *                                                                            the Order breakdown of Ticket_Type name => quantity.
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $qrPayload,
        public readonly string $qrPng,
        public readonly EffectiveBranding $branding,
        public readonly string $eventName,
        public readonly array $lineItems,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            to: [$this->order->customer_email],
            subject: 'Your tickets for '.$this->eventName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.ticket',
            with: [
                'order' => $this->order,
                'qrPayload' => $this->qrPayload,
                'qrPng' => $this->qrPng,
                'branding' => $this->branding,
                'eventName' => $this->eventName,
                'lineItems' => $this->lineItems,
            ],
        );
    }
}
