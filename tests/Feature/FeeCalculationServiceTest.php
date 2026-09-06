<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\FeeCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 9.2 — FeeCalculationService, the pure deterministic money engine.
 * All money in integer minor currency units.
 *
 * Requirements: 12.1 (Order_Total is the charge amount), 12.2 (round-half-up),
 * 12.3 (Company override × subtotal, clamped), 12.4 (Global × subtotal,
 * clamped), 13.4 (Absorb), 13.5 (Pass_On), 13.7 (free-only adds no booking
 * fee), 13.8 (mode snapshotted per order).
 */
class FeeCalculationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FeeCalculationService
    {
        return new FeeCalculationService;
    }

    // --- Pure calculate() path (no DB needed) --------------------------------

    public function test_absorb_order_total_equals_subtotal_and_no_booking_fee(): void
    {
        // Requirement 13.4 — Absorb: Order_Total = subtotal, Booking_Fee = 0.
        $result = $this->service()->calculate(10000, '5.00', Company::FEE_MODE_ABSORB);

        $this->assertSame(10000, $result->ticketSubtotal);
        $this->assertSame(500, $result->applicationFee);
        $this->assertSame(0, $result->bookingFee);
        $this->assertSame(10000, $result->orderTotal);
        $this->assertSame(Company::FEE_MODE_ABSORB, $result->feeHandlingMode);
    }

    public function test_pass_on_adds_booking_fee_to_order_total(): void
    {
        // Requirement 13.5 — Pass_On: Booking_Fee = fee, Order_Total = subtotal + fee.
        $result = $this->service()->calculate(10000, '5.00', Company::FEE_MODE_PASS_ON);

        $this->assertSame(500, $result->applicationFee);
        $this->assertSame(500, $result->bookingFee);
        $this->assertSame(10500, $result->orderTotal);
    }

    public function test_application_fee_rounds_half_up(): void
    {
        // Requirement 12.2 — round-half-up to the minor unit.
        // subtotal 150, 5.00% = 7.5 minor units -> rounds up to 8.
        $result = $this->service()->calculate(150, '5.00', Company::FEE_MODE_PASS_ON);
        $this->assertSame(8, $result->applicationFee);

        // subtotal 101, 5.00% = 5.05 -> rounds down to 5.
        $result = $this->service()->calculate(101, '5.00', Company::FEE_MODE_PASS_ON);
        $this->assertSame(5, $result->applicationFee);

        // subtotal 110, 5.00% = 5.5 -> half rounds up to 6.
        $result = $this->service()->calculate(110, '5.00', Company::FEE_MODE_PASS_ON);
        $this->assertSame(6, $result->applicationFee);
    }

    public function test_application_fee_never_exceeds_subtotal(): void
    {
        // Requirements 12.3, 12.4 — clamp to a maximum of the subtotal.
        $result = $this->service()->calculate(100, '150.00', Company::FEE_MODE_PASS_ON);

        $this->assertSame(100, $result->applicationFee);
        $this->assertSame(100, $result->bookingFee);
        $this->assertSame(200, $result->orderTotal);
    }

    public function test_zero_percent_yields_zero_fee(): void
    {
        // Requirements 12.3, 12.4 — clamp to a minimum of 0.
        $result = $this->service()->calculate(10000, '0.00', Company::FEE_MODE_PASS_ON);

        $this->assertSame(0, $result->applicationFee);
        $this->assertSame(0, $result->bookingFee);
        $this->assertSame(10000, $result->orderTotal);
    }

    public function test_free_only_order_is_all_zero_under_absorb(): void
    {
        // Requirements 12.9, 13.7 — free-only: everything zero regardless of mode.
        $result = $this->service()->calculate(0, '5.00', Company::FEE_MODE_ABSORB);

        $this->assertSame(0, $result->applicationFee);
        $this->assertSame(0, $result->bookingFee);
        $this->assertSame(0, $result->orderTotal);
        $this->assertTrue($result->isFree());
    }

    public function test_free_only_order_is_all_zero_under_pass_on(): void
    {
        // Requirement 13.7 — no Booking_Fee for free-only even under Pass_On.
        $result = $this->service()->calculate(0, '5.00', Company::FEE_MODE_PASS_ON);

        $this->assertSame(0, $result->applicationFee);
        $this->assertSame(0, $result->bookingFee);
        $this->assertSame(0, $result->orderTotal);
        $this->assertTrue($result->isFree());
    }

    public function test_fractional_percent_rounds_half_up(): void
    {
        // subtotal 1000, 12.34% = 123.4 -> rounds down to 123.
        $result = $this->service()->calculate(1000, '12.34', Company::FEE_MODE_ABSORB);
        $this->assertSame(123, $result->applicationFee);

        // subtotal 1000, 2.55% = 25.5 -> half rounds up to 26.
        $result = $this->service()->calculate(1000, '2.55', Company::FEE_MODE_ABSORB);
        $this->assertSame(26, $result->applicationFee);
    }

    public function test_negative_subtotal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->calculate(-1, '5.00', Company::FEE_MODE_ABSORB);
    }

    public function test_unsupported_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->calculate(100, '5.00', 'waive');
    }

    public function test_negative_percent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service()->calculate(100, '-5.00', Company::FEE_MODE_ABSORB);
    }

    // --- Company / PlatformSetting overloads (DB-backed) ---------------------

    public function test_effective_percent_uses_company_override_when_set(): void
    {
        // Requirement 12.3 — Company override takes precedence.
        PlatformSetting::create(['global_fee_percent' => '5.00']);
        $company = Company::create([
            'name' => 'Override Co',
            'slug' => 'override-co',
            'company_fee_percent' => '10.00',
        ]);

        $this->assertSame('10.00', $this->service()->effectivePercent($company));

        // subtotal 10000, 10% = 1000.
        $result = $this->service()->calculateForCompany($company, 10000);
        $this->assertSame(1000, $result->applicationFee);
    }

    public function test_effective_percent_falls_back_to_global_when_no_override(): void
    {
        // Requirement 12.4 — no override falls back to Global_Fee_Percent.
        PlatformSetting::create(['global_fee_percent' => '5.00']);
        $company = Company::create([
            'name' => 'Global Co',
            'slug' => 'global-co',
            'company_fee_percent' => null,
        ]);

        $this->assertSame('5.00', $this->service()->effectivePercent($company));

        $result = $this->service()->calculateForCompany($company, 10000);
        $this->assertSame(500, $result->applicationFee);
    }

    public function test_calculate_for_company_snapshots_the_current_mode(): void
    {
        // Requirement 13.8 — the mode used is the one snapshotted for the Order.
        PlatformSetting::create(['global_fee_percent' => '5.00']);
        $company = Company::create([
            'name' => 'Snapshot Co',
            'slug' => 'snapshot-co',
            'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
        ]);

        $result = $this->service()->calculateForCompany($company, 10000);

        $this->assertSame(Company::FEE_MODE_PASS_ON, $result->feeHandlingMode);
        $this->assertSame(10500, $result->orderTotal);
    }
}
