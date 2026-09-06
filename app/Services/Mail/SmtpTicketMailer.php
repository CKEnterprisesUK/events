<?php

namespace App\Services\Mail;

use App\Mail\TicketMail;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Branding\BrandingResolver;
use Illuminate\Contracts\Mail\Mailer;

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
    ) {}

    /**
     * Build the branded ticket email for the Order — resolving the effective
     * Event/Company branding and the Order breakdown of Ticket_Types and
     * quantities — and hand it to the mailer for delivery. (Requirements 14.4,
     * 14.6)
     */
    public function sendTicket(Order $order, string $qrPayload): void
    {
        $event = Event::withoutGlobalScopes()->findOrFail($order->event_id);

        $mailable = new TicketMail(
            order: $order,
            qrPayload: $qrPayload,
            branding: $this->branding->forEvent($event),
            eventName: $event->name,
            lineItems: $this->lineItems($order),
        );

        $this->mailer->send($mailable);
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
