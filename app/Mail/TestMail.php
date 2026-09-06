<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A minimal diagnostic email a Super_Admin can send to any address from the
 * platform Settings page to troubleshoot the configured mail transport. It
 * carries no tenant/Order context — its only purpose is to confirm the app can
 * connect to its mailer and deliver a message end-to-end.
 */
class TestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $recipient  the address the test is being delivered to.
     * @param  string  $mailerName  the mailer the send was dispatched through.
     */
    public function __construct(
        public readonly string $recipient,
        public readonly string $mailerName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Test email from '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test',
            with: [
                'recipient' => $this->recipient,
                'mailerName' => $this->mailerName,
                'appName' => config('app.name'),
                'sentAt' => now()->toDayDateTimeString(),
            ],
        );
    }
}
