<?php

namespace App\Mail;

use App\Models\Order;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\EffectiveBranding;
use App\Services\Mail\SmtpRefundMailer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The branded refund confirmation email for a refunded Order. Sent to the
 * Customer when a refund (full or partial) is issued from the dashboard, and
 * re-sendable on demand from the Order screen. Built by {@see SmtpRefundMailer}
 * and rendered from the `emails.refund` view.
 *
 * It tells the Customer how much has been refunded against their order and sets
 * the expectation that a card refund can take up to 7 working days to reappear
 * in their bank/card statement (the Stripe refund completes immediately on the
 * Platform's side, but the funds settle back to the issuing bank on the card
 * network's own timeline). A partial refund names the amount for *this* refund
 * and the cumulative total refunded so far; a full refund confirms the order is
 * now cancelled and its tickets voided.
 *
 * Like {@see TicketMail} it is presented as coming *from the selling Company*
 * (sender display name) over the Platform's SPF/DKIM-aligned sending address,
 * with the Company support address as reply-to, and the footer makes the
 * Platform/Company relationship explicit.
 */
class RefundConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Order  $order  the refunded Order (carries the recipient, reference and totals).
     * @param  EffectiveBranding  $branding  the resolved Event-override-else-Company branding (logo, primary colour).
     * @param  string  $eventName  the Event the order was for, shown in the subject and body.
     * @param  int  $amountMinor  the amount refunded by *this* refund, in minor currency units.
     * @param  int  $refundedTotalMinor  the cumulative amount refunded against the order to date, in minor units.
     * @param  int  $orderTotalMinor  the original order total, in minor units.
     * @param  bool  $fullyRefunded  whether the order is now refunded in full (tickets voided) vs a standing partial refund.
     * @param  string  $currency  the ISO currency code resolved from the selling Company (e.g. `GBP`).
     * @param  string  $companyName  the selling Company's public name — sender display name and the counterparty in the footer.
     * @param  string|null  $logoUrl  an absolute URL to the effective logo, ready as an <img> src; null when none is set.
     * @param  string|null  $supportEmail  the Company's support address for order queries; null when unset.
     */
    public function __construct(
        public readonly Order $order,
        public readonly EffectiveBranding $branding,
        public readonly string $eventName,
        public readonly int $amountMinor,
        public readonly int $refundedTotalMinor,
        public readonly int $orderTotalMinor,
        public readonly bool $fullyRefunded,
        public readonly string $currency,
        public readonly string $companyName,
        public readonly ?string $logoUrl = null,
        public readonly ?string $supportEmail = null,
    ) {}

    public function envelope(): Envelope
    {
        // Mirror TicketMail: send over the Platform's configured from-address
        // (SPF/DKIM-aligned on cPanel) but present the Company as the sender by
        // name so the attendee sees the organisation they bought from.
        $from = config('mail.from.address');

        return new Envelope(
            from: is_string($from) && $from !== ''
                ? new Address($from, $this->companyName)
                : null,
            to: [$this->order->customer_email],
            replyTo: $this->supportEmail !== null
                ? [new Address($this->supportEmail, $this->companyName)]
                : [],
            subject: ($this->fullyRefunded ? 'Your refund for ' : 'A partial refund for ').$this->eventName,
        );
    }

    public function content(): Content
    {
        $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
        $symbol = $symbols[$this->currency] ?? '';

        $money = fn (int $minor): string => $symbol.number_format($minor / 100, 2);

        return new Content(
            view: 'emails.refund',
            with: [
                'order' => $this->order,
                'branding' => $this->branding,
                'eventName' => $this->eventName,
                'amount' => $money($this->amountMinor),
                'refundedTotal' => $money($this->refundedTotalMinor),
                'orderTotal' => $money($this->orderTotalMinor),
                'fullyRefunded' => $this->fullyRefunded,
                'companyName' => $this->companyName,
                'logoUrl' => $this->logoUrl,
                'supportEmail' => $this->supportEmail,
            ],
        );
    }
}
