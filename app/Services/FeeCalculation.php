<?php

namespace App\Services;

/**
 * Immutable result of a fee calculation, all money in integer minor currency
 * units (e.g. pence). Produced by {@see FeeCalculationService}.
 *
 * - `applicationFee` is the Platform fee sent to Stripe as `application_fee_amount`
 *   (Requirement 12.2). It is always within `[0, ticketSubtotal]`.
 * - `bookingFee` is the amount added to the Customer's total under the Pass_On
 *   mode; it is 0 under Absorb (Requirements 13.4, 13.5).
 * - `orderTotal` is what the Customer pays / the direct-charge amount
 *   (Requirement 12.1).
 * - `feeHandlingMode` is the mode snapshotted for the Order at creation
 *   (Requirement 13.8) so a later mode change never mutates this Order.
 */
final class FeeCalculation
{
    public function __construct(
        public readonly int $ticketSubtotal,
        public readonly int $applicationFee,
        public readonly int $bookingFee,
        public readonly int $orderTotal,
        public readonly string $feeHandlingMode,
    ) {}

    /**
     * Whether this Order is free-only (zero subtotal), in which case all money
     * is zero and no Stripe charge is created (Requirements 12.9, 13.7).
     */
    public function isFree(): bool
    {
        return $this->orderTotal === 0;
    }

    /**
     * Array form for persisting the snapshot onto an Order row.
     *
     * @return array{ticket_subtotal_minor:int,application_fee_minor:int,booking_fee_minor:int,order_total_minor:int,fee_handling_mode:string}
     */
    public function toArray(): array
    {
        return [
            'ticket_subtotal_minor' => $this->ticketSubtotal,
            'application_fee_minor' => $this->applicationFee,
            'booking_fee_minor' => $this->bookingFee,
            'order_total_minor' => $this->orderTotal,
            'fee_handling_mode' => $this->feeHandlingMode,
        ];
    }
}
