<?php

namespace App\Services\Mail;

use App\Models\Order;

/**
 * A deterministic {@see TicketMailer} for automated tests: it records each send
 * instead of dispatching a real email, so tests can assert that fulfilment sent
 * the ticket (and with which QR payload) without any SMTP transport. Bound in
 * the container in the testing environment so no test ever sends real email.
 * (design → Testing Strategy; Requirements 14.4, 14.5)
 */
class FakeTicketMailer implements TicketMailer
{
    /**
     * The recorded sends: one entry per {@see sendTicket()} call.
     *
     * @var array<int, array{order_id: int, order_reference: string, email: string, qr_payload: string}>
     */
    public array $sent = [];

    public function sendTicket(Order $order, string $qrPayload): void
    {
        $this->sent[] = [
            'order_id' => (int) $order->getKey(),
            'order_reference' => $order->order_reference,
            'email' => $order->customer_email,
            'qr_payload' => $qrPayload,
        ];
    }

    /**
     * Whether a ticket email was recorded for the given Order id.
     */
    public function sentFor(int $orderId): bool
    {
        foreach ($this->sent as $record) {
            if ($record['order_id'] === $orderId) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many ticket emails were recorded for the given Order id.
     */
    public function countFor(int $orderId): int
    {
        return count(array_filter(
            $this->sent,
            static fn (array $record): bool => $record['order_id'] === $orderId,
        ));
    }
}
