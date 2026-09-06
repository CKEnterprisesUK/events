<?php

namespace App\Services\Mail;

use App\Mail\TicketMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Branding\BrandingResolver;
use App\Services\Branding\EffectiveBranding;
use App\Services\QrService;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Storage;

/**
 * The default {@see TicketMailer}: renders the branded {@see TicketMail} and
 * sends it through the configured mailer (cPanel SMTP in production). Because
 * every caller depends only on the {@see TicketMailer} interface, swapping this
 * for an API-backed mailer later is a one-line container rebind with no change
 * to the fulfilment/job code. (Requirements 14.4, 14.5)
 */
class SmtpTicketMailer implements TicketMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly BrandingResolver $branding,
        private readonly QrService $qr,
    ) {}

    /**
     * Build the branded ticket email for the Order — resolving the effective
     * Event/Company branding and the Order breakdown of Ticket_Types and
     * quantities — and hand it to the mailer for delivery. (Requirements 14.4,
     * 14.6)
     */
    public function sendTicket(Order $order, string $qrPayload): void
    {
        // Load the Event with its owning Company (no resolved tenant on the
        // queue, so bypass the scope). The Company drives the sender name,
        // reply-to, and the legal footer naming the selling organisation.
        $event = Event::withoutGlobalScopes()->findOrFail($order->event_id);
        $company = $event->company;

        $branding = $this->branding->forEvent($event);

        $mailable = new TicketMail(
            order: $order,
            qrPayload: $qrPayload,
            qrPng: $this->qr->png($qrPayload),
            branding: $branding,
            eventName: $event->name,
            lineItems: $this->lineItems($order),
            companyName: $company?->name ?? config('app.name'),
            logoUrl: $this->logoUrl($branding),
            supportEmail: $company?->support_email,
        );

        $this->mailer->send($mailable);
    }

    /**
     * Resolve the effective logo to an absolute URL for use as an email <img>
     * src. Stored logos live on the public disk as relative paths, which render
     * fine on-site through a relative URL but must be absolute in an email
     * (there is no page origin to resolve against). Returns null when either no
     * logo is set or it is already an absolute URL.
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

    /**
     * The Order breakdown: each purchased Ticket_Type and its quantity, read
     * from the Order's Tickets. Bypasses the tenant scope because fulfilment
     * runs in service/queue context with no resolved Company; the Order is
     * supplied by the (already scoped) caller. (Requirement 14.6)
     *
     * @return array<int, array{ticket_type: string, quantity: int}>
     */
    private function lineItems(Order $order): array
    {
        $counts = Ticket::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->selectRaw('ticket_type_id, COUNT(*) as qty')
            ->groupBy('ticket_type_id')
            ->pluck('qty', 'ticket_type_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $names = TicketType::withoutGlobalScopes()
            ->whereIn('id', $counts->keys()->all())
            ->pluck('name', 'id');

        $items = [];

        foreach ($counts as $ticketTypeId => $qty) {
            $items[] = [
                'ticket_type' => (string) ($names[$ticketTypeId] ?? "Ticket #{$ticketTypeId}"),
                'quantity' => (int) $qty,
            ];
        }

        return $items;
    }
}
