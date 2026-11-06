<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Alerts Super_Admins that 403/404/500 responses are running above baseline.
 */
class ErrorSpikeAlertMail extends Mailable
{
    use Queueable;

    /**
     * @param  list<array{status: int, current: int, mean: float, threshold: float}>  $spikes
     */
    public function __construct(public readonly array $spikes) {}

    public function envelope(): Envelope
    {
        $codes = implode(', ', array_map(fn ($s) => (string) $s['status'], $this->spikes));

        return new Envelope(subject: 'Error spike detected: HTTP '.$codes);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.error-spike-alert',
            with: [
                'spikes' => $this->spikes,
                'errorsUrl' => route('admin.errors.index'),
            ],
        );
    }
}
