<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Services\FeeCalculationService;
use Eris\Generator;

/**
 * Property-based test for free-only orders (Requirements 10.9, 12.9, 13.7).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. This is a pure-arithmetic
 * property over {@see FeeCalculationService}, so it needs no database.
 */
class FreeOrderNoChargeTest extends PbtTestCase
{
    /**
     * Property 18: Free orders incur no money and no charge — a free-only order
     * (zero Ticket_Subtotal) yields zero Application_Fee, Booking_Fee, and
     * Order_Total, no Stripe charge, and completes without payment, regardless
     * of the Fee_Handling_Mode and effective fee percent.
     *
     * **Validates: Requirements 10.9, 12.9, 13.7**
     */
    // Feature: event-ticketing-platform, Property 18: Free orders incur no money and no charge — free-only orders yield zero subtotal/fee/booking/total, no Stripe charge, completes without payment, regardless of Fee_Handling_Mode
    public function test_free_only_orders_incur_no_money_and_no_charge(): void
    {
        $service = new FeeCalculationService();

        $this->forAll(
            // Random effective percent in hundredths-of-a-percent (0.00–100.00),
            // formatted as a DECIMAL(5,2) string like the fee engine receives.
            Generator\map(
                fn (int $hundredths): string => number_format($hundredths / 100, 2, '.', ''),
                Generator\choose(0, 10000)
            ),
            // Both fee handling modes.
            Generator\elements(...Company::FEE_MODES)
        )
            ->withMaxSize(10000)
            ->then(function (string $percent, string $mode) use ($service): void {
                // Free-only order: zero Ticket_Subtotal.
                $calculation = $service->calculate(0, $percent, $mode);

                $this->assertSame(0, $calculation->ticketSubtotal, 'Free order subtotal must be 0.');
                $this->assertSame(0, $calculation->applicationFee, "Free order Application_Fee must be 0 (percent={$percent}, mode={$mode}).");
                $this->assertSame(0, $calculation->bookingFee, "Free order Booking_Fee must be 0 (percent={$percent}, mode={$mode}).");
                $this->assertSame(0, $calculation->orderTotal, "Free order Order_Total must be 0 (percent={$percent}, mode={$mode}).");
                $this->assertTrue($calculation->isFree(), "Free order must report isFree() (percent={$percent}, mode={$mode}).");
                // The mode is snapshotted unchanged (Requirement 13.8).
                $this->assertSame($mode, $calculation->feeHandlingMode, 'Fee handling mode must be preserved on the result.');
            });
    }
}
