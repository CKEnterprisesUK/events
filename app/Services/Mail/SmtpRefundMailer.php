<?php

namespace App\Services\Mail;

use App\Mail\RefundConfirmationMail;
use App\Models\Event;
use App\Models\Order;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\EffectiveBranding;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Storage;

/**
 * The default {@see RefundMailer}: renders the branded
 * {@see RefundConfirmationMail} and sends it through the configured mailer
 * (cPanel SMTP in production). Mirrors {@see SmtpTicketMailer} — because every
 * caller depends only on the {@see RefundMailer} interface, swapping this for
 * an API-backed mailer later is a one-line container rebind with no change to
 * the job/controller code.
 */
class SmtpRefundMailer implements RefundMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly BrandingResolver $branding,
    ) {}

    /**
     * Build the branded refund confirmation email for the Order — resolving the
     * effective Event/Company branding and the selling Company's currency and
     * support address — and hand it to the mailer for delivery.
     */
    public function sendRefund(Order $order, int $amountMinor, bool $fullyRefunded): void
    {
        // Load the Event with its owning Company (no resolved tenant on the
        // queue, so bypass the scope). The Company drives the sender name,
        // reply-to, currency and the legal footer naming the selling company.
        $event = Event::withoutGlobalScopes()->findOrFail($order->event_id);
        $company = $event->company;

        $branding = $this->branding->forEvent($event);

        $mailable = new RefundConfirmationMail(
            order: $order,
            branding: $branding,
            eventName: $event->name,
            amountMinor: $amountMinor,
            refundedTotalMinor: (int) $order->refunded_total_minor,
            orderTotalMinor: (int) $order->order_total_minor,
            fullyRefunded: $fullyRefunded,
            currency: $company?->currency ?? 'GBP',
            companyName: $company?->name ?? config('app.name'),
            logoUrl: $this->logoUrl($branding),
            supportEmail: $company?->support_email,
        );

        $this->mailer->send($mailable);
    }

    /**
     * Resolve the effective logo to an absolute URL for use as an email <img>
     * src. Stored logos live on the public disk as relative paths, which must
     * be absolute in an email (there is no page origin to resolve against).
     * Returns null when no logo is set, or the value unchanged when already
     * absolute. (Mirrors {@see SmtpTicketMailer::logoUrl()}.)
     */
    private function logoUrl(EffectiveBranding $branding): ?string
    {
        if (! $branding->hasLogo()) {
            return null;
        }

        $path = (string) $branding->logoPath;

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url(Storage::disk('public')->url($path));
    }
}
