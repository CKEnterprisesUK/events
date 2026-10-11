<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A platform-wide operational announcement a Super_Admin sends to a chosen set
 * of Company_Users (by role, optionally within one Company). These are
 * essential service communications about the Platform itself — not marketing —
 * so no consent/unsubscribe gate applies; the audience is picked explicitly by
 * the operator.
 *
 * The body is authored as Markdown and rendered to safe HTML by the caller
 * (mirroring the Trust & Legal centre: `html_input => 'escape'`), so raw HTML in
 * the source is neutralised and no untrusted markup reaches the recipient. The
 * mailable therefore carries the ALREADY-rendered, trusted HTML plus a plain
 * subject and the recipient's name for a greeting.
 */
class PlatformAnnouncementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $subjectLine  the email subject.
     * @param  string  $bodyHtml  the announcement body, pre-rendered to safe HTML.
     * @param  string  $recipientName  the recipient's name, for the greeting.
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyHtml,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.announcement',
            with: [
                'subjectLine' => $this->subjectLine,
                'bodyHtml' => $this->bodyHtml,
                'recipientName' => $this->recipientName,
                'appName' => config('app.name'),
            ],
        );
    }
}
