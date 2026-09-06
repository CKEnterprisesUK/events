<?php

namespace App\Mail;

use App\Models\SupportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies the CK Enterprises support inbox that a Company_User has raised a
 * new support ticket from the in-dashboard "Contact support" form.
 *
 * Addressed to the platform support mailbox (`config('mail.support.address')`,
 * default support@ckenterprises.co.uk), NOT the raising Company — this is the
 * operator's own alert. The reply-to is set to the raiser's email where known
 * so replying from the inbox reaches them directly. The body carries the
 * ticket's company, raiser, category, subject, message and whether account
 * access was granted, so an operator can triage without opening the dashboard.
 */
class SupportRequestReceivedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly SupportRequest $supportRequest,
        public readonly string $companyName,
        public readonly ?string $raiserName,
        public readonly ?string $raiserEmail,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = $this->raiserEmail !== null && $this->raiserEmail !== ''
            ? [new Address($this->raiserEmail, $this->raiserName ?? $this->raiserEmail)]
            : [];

        return new Envelope(
            subject: 'New support request: '.$this->supportRequest->subject,
            replyTo: $replyTo,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-request-received',
            with: [
                'ticket' => $this->supportRequest,
                'companyName' => $this->companyName,
                'raiserName' => $this->raiserName,
                'raiserEmail' => $this->raiserEmail,
                'categoryLabel' => $this->supportRequest->categoryLabel(),
                'accessConsent' => (bool) $this->supportRequest->access_consent,
            ],
        );
    }
}
