<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation email an Owner sends when inviting a user (by email) to join
 * their Company with an assigned role. (Requirement 4.1)
 *
 * Carries the public accept link keyed on the invitation's opaque token, so the
 * recipient can set a password and join the inviting Company. Presented as
 * coming *from the Company* by name (over the Platform's SPF/DKIM-aligned
 * sending address), consistent with the branded ticket email.
 */
class InvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Invitation  $invitation  the pending invitation being delivered.
     * @param  string  $acceptUrl  absolute URL to the public accept form.
     * @param  string  $roleLabel  human-readable label for the assigned role.
     * @param  string  $companyName  the inviting Company's name — sender display name and body copy.
     */
    public function __construct(
        public readonly Invitation $invitation,
        public readonly string $acceptUrl,
        public readonly string $roleLabel,
        public readonly string $companyName,
    ) {}

    public function envelope(): Envelope
    {
        // Send over the Platform's configured from-address (SPF/DKIM-aligned on
        // cPanel) but present the Company as the sender by name, mirroring the
        // ticket email so the recipient sees who invited them.
        $from = config('mail.from.address');

        return new Envelope(
            from: is_string($from) && $from !== ''
                ? new Address($from, $this->companyName)
                : null,
            subject: 'You have been invited to join '.$this->companyName,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invitation',
            with: [
                'acceptUrl' => $this->acceptUrl,
                'roleLabel' => $this->roleLabel,
                'companyName' => $this->companyName,
                'email' => $this->invitation->email,
                'expiresAt' => $this->invitation->expires_at,
                'appName' => config('app.name'),
            ],
        );
    }
}
