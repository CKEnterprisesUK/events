<?php

namespace App\Services\Mail\Graph;

use RuntimeException;
use Throwable;

/**
 * Raised when a Microsoft Graph mail call (token acquisition or `sendMail`)
 * fails. Carries the HTTP status and Graph error code alongside the message so
 * the Super_Admin diagnostics screen can render a targeted hint (e.g. a 403 on
 * `sendMail` points at the `Mail.Send` application permission or an Application
 * Access Policy, whereas a 401 on the token step points at a bad client secret
 * or tenant/client id).
 */
class GraphMailException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $graphCode = null,
        public readonly string $stage = 'send',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A short, operator-facing hint for the most common failure shapes, tuned
     * to the exact troubleshooting steps for Graph application-only mail. Null
     * when there is nothing specific to add beyond the raw Graph message.
     */
    public function hint(): ?string
    {
        // Token stage: the app itself could not authenticate.
        if ($this->stage === 'token') {
            return 'The app could not authenticate to Microsoft. Check GRAPH_MAIL_TENANT_ID, '
                .'GRAPH_MAIL_CLIENT_ID and GRAPH_MAIL_CLIENT_SECRET (a client secret that has '
                .'expired is a common cause).';
        }

        // Send stage: authenticated fine, but not allowed to send as the mailbox.
        return match ($this->status) {
            403 => 'The app authenticated but is not allowed to send as this mailbox. Confirm the '
                .'Mail.Send APPLICATION permission is granted with admin consent, that GRAPH_MAIL_FROM '
                .'is a real licensed Exchange Online mailbox, and that any Application Access Policy '
                .'includes this mailbox.',
            404 => 'The sending mailbox (GRAPH_MAIL_FROM) was not found in the tenant. Confirm it is a '
                .'real, licensed mailbox addressed by its user principal name or object id.',
            401 => 'The access token was rejected. Re-check the app registration credentials and that '
                .'admin consent has been granted for the Mail.Send application permission.',
            default => null,
        };
    }
}
