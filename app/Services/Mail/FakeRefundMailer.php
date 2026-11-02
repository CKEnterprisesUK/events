<?php

namespace App\Services\Mail;

use App\Models\Order;

/**
 * A deterministic {@see RefundMailer} for automated tests: it records each send
 * instead of dispatching a real email, so tests can assert that a refund sent
 * the confirmation (and for how much) without any SMTP transport. Bound in the
 * container in the testing environment so no test ever sends real email.
 * (Mirrors {@see FakeTicketMailer}.)
 */
class FakeRefundMailer implements RefundMailer
{
    /**
     * The recorded sends: one entry per {@see sendRefund()} call.
     *
     * @var array<int, array{order_id: int, order_reference: string, email: string, amount_minor: int, fully_refunded: bool}>
     */
    public array $sent = [];

    public function sendRefund(Order $order, int $amountMinor, bool $fullyRefunded): void
    {
        $this->sent[] = [
            'order_id' => (int) $order->getKey(),
            'order_reference' => $order->order_reference,
            'email' => $order->customer_email,
            'amount_minor' => $amountMinor,
            'fully_refunded' => $fullyRefunded,
        ];
    }

    /**
     * Whether a refund email was recorded for the given Order id.
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
     * How many refund emails were recorded for the given Order id.
     */
    public function countFor(int $orderId): int
    {
        return count(array_filter(
            $this->sent,
            static fn (array $record): bool => $record['order_id'] === $orderId,
        ));
    }
}
