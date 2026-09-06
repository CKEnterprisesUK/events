<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PlatformSetting;

/**
 * Pure, deterministic money engine (Requirements 12.2–12.4, 13.4–13.8).
 *
 * Given a Ticket_Subtotal (integer minor currency units), an effective fee
 * percent, and a Fee_Handling_Mode, it computes Application_Fee, Booking_Fee,
 * and Order_Total. It performs no database writes and reads no request state:
 * callers pass in everything it needs. The Company/PlatformSetting overloads
 * only *read* the effective percent and mode off the given models.
 *
 * Rules:
 * - effective percent = Company override (`company_fee_percent`) when set,
 *   otherwise the Global_Fee_Percent (Requirements 12.3, 12.4).
 * - Application_Fee = subtotal × percent, rounded half-up to the minor unit,
 *   then clamped to `[0, subtotal]` (Requirements 12.2, 12.3, 12.4).
 * - Absorb → Order_Total = subtotal, Booking_Fee = 0 (Requirement 13.4).
 * - Pass_On → Booking_Fee = Application_Fee, Order_Total = subtotal +
 *   Booking_Fee (Requirement 13.5).
 * - Free-only (subtotal 0) → all money zero regardless of mode
 *   (Requirements 12.9, 13.7).
 */
class FeeCalculationService
{
    /**
     * Percentages carry two decimal places (DECIMAL(5,2)). Scaling by 100 turns
     * a percent into an integer count of hundredths-of-a-percent so the fee
     * arithmetic stays in integers and avoids binary float rounding surprises.
     */
    private const PERCENT_SCALE = 100;

    /**
     * Denominator once both the percent scaling (×100) and the percent→fraction
     * conversion (÷100) are folded together: subtotal × (percentHundredths) is
     * divided by 100 × 100 = 10000.
     */
    private const FEE_DIVISOR = 10000;

    /**
     * Compute the fee breakdown from primitive inputs.
     *
     * @param  int  $ticketSubtotal  Ticket_Subtotal in integer minor currency units (>= 0).
     * @param  string|float|int  $effectivePercent  The effective fee percent (e.g. "5.00").
     * @param  string  $feeHandlingMode  One of {@see Company::FEE_MODES}.
     *
     * @throws \InvalidArgumentException on a negative subtotal, negative percent, or unknown mode.
     */
    public function calculate(int $ticketSubtotal, string|float|int $effectivePercent, string $feeHandlingMode): FeeCalculation
    {
        if ($ticketSubtotal < 0) {
            throw new \InvalidArgumentException("Ticket_Subtotal must be non-negative, got {$ticketSubtotal}.");
        }

        if (! in_array($feeHandlingMode, Company::FEE_MODES, true)) {
            throw new \InvalidArgumentException("Unsupported fee handling mode: {$feeHandlingMode}");
        }

        // Free-only orders: no fee, no booking fee, no total, regardless of mode
        // (Requirements 12.9, 13.7).
        if ($ticketSubtotal === 0) {
            return new FeeCalculation(
                ticketSubtotal: 0,
                applicationFee: 0,
                bookingFee: 0,
                orderTotal: 0,
                feeHandlingMode: $feeHandlingMode,
            );
        }

        $applicationFee = $this->applicationFee($ticketSubtotal, $effectivePercent);

        if ($feeHandlingMode === Company::FEE_MODE_PASS_ON) {
            // Pass_On: Customer pays the fee on top of the subtotal (Requirement 13.5).
            $bookingFee = $applicationFee;
            $orderTotal = $ticketSubtotal + $bookingFee;
        } else {
            // Absorb: Order_Total equals the subtotal; no booking fee (Requirement 13.4).
            $bookingFee = 0;
            $orderTotal = $ticketSubtotal;
        }

        return new FeeCalculation(
            ticketSubtotal: $ticketSubtotal,
            applicationFee: $applicationFee,
            bookingFee: $bookingFee,
            orderTotal: $orderTotal,
            feeHandlingMode: $feeHandlingMode,
        );
    }

    /**
     * Convenience overload: compute directly from a Company, reading its
     * effective percent (override else Global) and Fee_Handling_Mode. The mode
     * is read here and returned in the result so the caller can snapshot it on
     * the Order (Requirement 13.8).
     */
    public function calculateForCompany(Company $company, int $ticketSubtotal): FeeCalculation
    {
        return $this->calculate(
            $ticketSubtotal,
            $this->effectivePercent($company),
            $company->fee_handling_mode,
        );
    }

    /**
     * The effective fee percent for a Company: its `company_fee_percent`
     * override when set, otherwise the Platform's Global_Fee_Percent
     * (Requirements 12.3, 12.4).
     */
    public function effectivePercent(Company $company): string
    {
        $override = $company->company_fee_percent;

        if ($override !== null) {
            return (string) $override;
        }

        return (string) PlatformSetting::current()->global_fee_percent;
    }

    /**
     * Application_Fee = round-half-up(subtotal × percent / 100), clamped to
     * `[0, subtotal]` (Requirements 12.2, 12.3, 12.4).
     */
    private function applicationFee(int $ticketSubtotal, string|float|int $effectivePercent): int
    {
        $percentHundredths = $this->percentToHundredths($effectivePercent);

        if ($percentHundredths < 0) {
            throw new \InvalidArgumentException("Fee percent must be non-negative, got {$effectivePercent}.");
        }

        // numerator = subtotal × percentHundredths; divisor = 10000.
        $numerator = $ticketSubtotal * $percentHundredths;
        $fee = $this->divRoundHalfUp($numerator, self::FEE_DIVISOR);

        // Clamp to [0, subtotal].
        if ($fee < 0) {
            $fee = 0;
        } elseif ($fee > $ticketSubtotal) {
            $fee = $ticketSubtotal;
        }

        return $fee;
    }

    /**
     * Convert a DECIMAL(5,2) percent (as string/float/int) into an integer
     * number of hundredths-of-a-percent, e.g. "5.00" → 500, "12.34" → 1234.
     * Rounds half-up at the second decimal place to guard against float inputs
     * such as 12.345.
     */
    private function percentToHundredths(string|float|int $percent): int
    {
        // Multiply by the scale then round to the nearest integer, half-up.
        $scaled = (float) $percent * self::PERCENT_SCALE;

        return (int) floor($scaled + 0.5);
    }

    /**
     * Integer division of a non-negative numerator by a positive divisor,
     * rounding half-up (ties go up). Kept in integer arithmetic so results are
     * exact for the fee sizes involved.
     */
    private function divRoundHalfUp(int $numerator, int $divisor): int
    {
        $quotient = intdiv($numerator, $divisor);
        $remainder = $numerator % $divisor;

        // Round half up: bump when twice the remainder reaches the divisor.
        if ($remainder * 2 >= $divisor) {
            $quotient++;
        }

        return $quotient;
    }
}
