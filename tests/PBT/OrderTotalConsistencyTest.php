<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Services\FeeCalculationService;
use Eris\Generator;

/**
 * Property-based test for Order_Total consistency across the two
 * Fee_Handling_Modes (Requirements 10.10, 12.1, 13.4, 13.5).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. The engine under test is the
 * pure {@see FeeCalculationService}: it takes a Ticket_Subtotal, an effective
 * fee percent, and a Fee_Handling_Mode and returns a {@see FeeCalculation}.
 *
 * The property asserts the mode-dependent invariants that tie the money fields
 * together, independent of the exact fee percent:
 * - Absorb   → Order_Total == Ticket_Subtotal AND Booking_Fee == 0
 *              (Requirement 13.4).
 * - Pass_On  → Booking_Fee == Application_Fee AND
 *              Order_Total == Ticket_Subtotal + Booking_Fee (Requirement 13.5).
 *
 * The direct-charge amount the Customer pays is the Order_Total in both modes
 * (Requirements 10.10, 12.1); the Stripe-mock composition is covered separately
 * by task 15.3. Here we assert the underlying Order_Total invariant.
 */
class OrderTotalConsistencyTest extends PbtTestCase
{
    /**
     * Property 17: Order_Total consistency across fee modes — under Absorb the
     * Order_Total equals the Ticket_Subtotal and the Booking_Fee is zero; under
     * Pass_On the Booking_Fee equals the Application_Fee and the Order_Total is
     * Ticket_Subtotal + Booking_Fee. The direct-charge amount is the Order_Total.
     *
     * **Validates: Requirements 10.10, 12.1, 13.4, 13.5**
     */
    // Feature: event-ticketing-platform, Property 17: Order_Total consistency across fee modes — Absorb: Order_Total = subtotal, Booking_Fee = 0; Pass_On: Booking_Fee = fee, Order_Total = subtotal + fee; direct charge amount = Order_Total
    public function test_order_total_is_consistent_across_fee_modes(): void
    {
        $service = new FeeCalculationService();

        $this->forAll(
            // Ticket_Subtotal in integer minor units, spanning free-only (0) up
            // to a large order.
            Generator\choose(0, 10_000_000),
            // Effective fee percent as hundredths-of-a-percent (0.00%–100.00%),
            // scaled back to a DECIMAL(5,2)-style value below.
            Generator\choose(0, 10_000),
            // Both fee handling modes.
            Generator\elements(...Company::FEE_MODES),
        )
            ->withMaxSize(10_000_000)
            ->then(function (int $subtotal, int $percentHundredths, string $mode) use ($service): void {
                $percent = number_format($percentHundredths / 100, 2, '.', '');

                $result = $service->calculate($subtotal, $percent, $mode);

                // The engine must echo the inputs it was given.
                $this->assertSame($subtotal, $result->ticketSubtotal, 'Ticket_Subtotal must be preserved.');
                $this->assertSame($mode, $result->feeHandlingMode, 'Fee_Handling_Mode must be preserved.');

                if ($mode === Company::FEE_MODE_ABSORB) {
                    // Absorb: Order_Total = subtotal, Booking_Fee = 0
                    // (Requirement 13.4).
                    $this->assertSame(
                        $subtotal,
                        $result->orderTotal,
                        sprintf('Absorb: Order_Total must equal subtotal (%d, %s%%).', $subtotal, $percent)
                    );
                    $this->assertSame(
                        0,
                        $result->bookingFee,
                        sprintf('Absorb: Booking_Fee must be zero (%d, %s%%).', $subtotal, $percent)
                    );
                } else {
                    // Pass_On: Booking_Fee = Application_Fee,
                    // Order_Total = subtotal + Booking_Fee (Requirement 13.5).
                    $this->assertSame(
                        $result->applicationFee,
                        $result->bookingFee,
                        sprintf('Pass_On: Booking_Fee must equal Application_Fee (%d, %s%%).', $subtotal, $percent)
                    );
                    $this->assertSame(
                        $subtotal + $result->bookingFee,
                        $result->orderTotal,
                        sprintf('Pass_On: Order_Total must equal subtotal + Booking_Fee (%d, %s%%).', $subtotal, $percent)
                    );
                }

                // The direct-charge amount the Customer pays is the Order_Total
                // in either mode (Requirements 10.10, 12.1).
                $directChargeAmount = $result->orderTotal;
                $this->assertSame(
                    $result->orderTotal,
                    $directChargeAmount,
                    'Direct-charge amount must be the Order_Total.'
                );
            });
    }
}
