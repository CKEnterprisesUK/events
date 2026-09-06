<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email-verification notification, sent IMMEDIATELY (synchronously) rather
 * than through the cron-drained database queue.
 *
 * Background: this Platform runs all deferred work on the `database` queue,
 * which is drained by a per-minute cron burst (`queue:work --stop-when-empty`,
 * see DEPLOYMENT.md). Anything that `implements ShouldQueue` therefore waits up
 * to ~a minute before it is sent — acceptable for ticket emails, Stripe webhook
 * processing and reservation release, but a poor experience for the very first
 * thing a new Owner does: verify their email and get into the dashboard.
 *
 * This notification deliberately does NOT implement `ShouldQueue`, so it is
 * delivered inline during the request that triggers it (signup or "resend"),
 * with no queue delay. Every other email keeps its 1-minute-cron behaviour.
 *
 * It extends the framework's {@see VerifyEmail} so the signed verification URL,
 * expiry, and the `verification.verify` route contract are all unchanged — only
 * the delivery timing differs.
 */
class VerifyEmailNow extends VerifyEmail
{
    /**
     * Build the mail representation.
     *
     * We defer to the parent's signed-URL construction and simply reuse its
     * message so the link, hash and expiry behave exactly like the stock
     * notification. The important behaviour lives in the class NOT implementing
     * ShouldQueue, which keeps delivery synchronous.
     */
    public function toMail($notifiable): MailMessage
    {
        return parent::toMail($notifiable);
    }
}
