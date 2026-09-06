<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\EffectiveBranding;
use App\Services\Mail\SmtpTicketMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
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
 *
 * The email is presented as coming *from the Company* the attendee bought from:
 * the sender display name is the Company name (over the Platform's configured,
 * SPF/DKIM-aligned sending address, since cPanel SMTP authenticates as the
 * Platform and cannot send as arbitrary Company domains). The footer then makes
 * the Platform relationship explicit — the Company used Events by CK Enterprises
 * UK as its ticketing platform, and the sale contract is with the Company.
 */
class TicketMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<int, array{ticket_type: string, quantity: int}>  $lineItems
     *                                                                            the Order breakdown of Ticket_Type name => quantity.
     * @param  string  $companyName  the selling Company's public name — the email's sender display name and the legal counterparty named in the footer.
     * @param  string|null  $logoUrl  an absolute URL to the effective (Event-override-else-Company) logo, ready to use as an <img> src; null when no logo is set.
     * @param  string|null  $supportEmail  the Company's support address, shown to the attendee for order queries; null when the Company has not set one.
     */
    public function __construct(
        public readonly Order $order,
        public readonly string $qrPayload,
        public readonly string $qrPng,
        public readonly EffectiveBranding $branding,
        public readonly string $eventName,
        public readonly array $lineItems,
        public readonly string $companyName,
        public readonly ?string $logoUrl = null,
        public readonly ?string $supportEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        // Send over the Platform's configured from-address (SPF/DKIM-aligned on
        // cPanel) but present the Company as the sender by name, so the attendee
        // sees the organisation they bought from in their inbox.
        $from = config('mail.from.address');

        return new Envelope(
            from: is_string($from) && $from !== ''
                ? new Address($from, $this->companyName)
                : null,
            to: [$this->order->customer_email],
            replyTo: $this->supportEmail !== null
                ? [new Address($this->supportEmail, $this->companyName)]
                : [],
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
                'companyName' => $this->companyName,
                'logoUrl' => $this->logoUrl,
                'supportEmail' => $this->supportEmail,
            ],
        );
    }
}
