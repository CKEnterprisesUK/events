<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * The deliberately separate ticket metrics for one scope (a company or a
 * single event). Produced by {@see TicketMetrics}, the single source of truth
 * for these definitions, so dashboards, exports and door tools never diverge.
 *
 *  - issued:     every Ticket row ever created (any status, any order state).
 *  - sold:       valid tickets on confirmed (paid / free-confirmed) orders.
 *  - voided:     tickets voided for any reason (cancel, refund, dispute, expiry).
 *  - refunded:   the subset of voided tickets that sit on refunded orders.
 *  - checkedIn:  sold tickets whose order has been scanned at the door.
 */
final class TicketCounts
{
    public function __construct(
        public readonly int $issued = 0,
        public readonly int $sold = 0,
        public readonly int $voided = 0,
        public readonly int $refunded = 0,
        public readonly int $checkedIn = 0,
    ) {}

    /**
     * Tickets still to arrive through the door.
     */
    public function awaitingCheckIn(): int
    {
        return max(0, $this->sold - $this->checkedIn);
    }
}
