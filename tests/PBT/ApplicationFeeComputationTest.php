<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\FeeCalculationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the Application_Fee money engine: computation,
 * effective-percent selection, clamping, and round-half-up rounding
 * (Requirements 12.2, 12.3, 12.4, 20.5, 20.6).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Generators are engineered to
 * hit round-half-up tie boundaries and to push the raw fee above the subtotal
 * so the `[0, subtotal]` clamp is exercised.
 */
class ApplicationFeeComputationTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * Independent oracle: Application_Fee = round-half-up(subtotal × percent
     * / 100) clamped to `[0, subtotal]`.
     *
     * Computed here with bcmath at high precision and an explicit half-up
     * rounding step, deliberately using a different code path from the
     * service (which folds everything into scaled integer arithmetic) so the
     * two agreeing is meaningful.
     */
    private function expectedApplicationFee(int $subtotal, string $percent): int
    {
        // Raw fee = subtotal * percent / 100, kept exact to plenty of decimals.
        $raw = bcdiv(bcmul((string) $subtotal, $percent, 10), '100', 10);

        // Round half up to an integer minor unit: add 0.5 then truncate toward
        // zero. Inputs here are always non-negative, so truncation == floor.
        $rounded = (int) bcadd($raw, '0.5', 0);

        // Clamp to [0, subtotal].
        if ($rounded < 0) {
            return 0;
        }

        if ($rounded > $subtotal) {
            return $subtotal;
        }

        return $rounded;
    }

    /**
     * Property 16: Application fee computation, selection, clamping, and
     * rounding — fee = subtotal × effective percent, round-half-up, clamped
     * to `[0, subtotal]`; `application_fee_amount` equals this. The effective
     * percent is the Company override when set, otherwise the Global_Fee_Percent.
     *
     * **Validates: Requirements 12.2, 12.3, 12.4, 20.5, 20.6**
     */
    // Feature: event-ticketing-platform, Property 16: Application fee computation, selection, clamping, and rounding — fee = round-half-up(subtotal × effective percent), clamped [0, subtotal]; application_fee_amount equals this; effective percent is company override else global
    public function test_application_fee_is_round_half_up_clamped_and_uses_effective_percent(): void
    {
        $service = new FeeCalculationService();

        // Fixed boundary cases: values engineered to land exactly on a
        // round-half-up tie, plus clamp edges (0% and >100%).
        $boundaryCases = [
            // [subtotal, percent]
            [1000, '5.00'],    // exact: 50, no rounding
            [1, '50.00'],      // raw 0.5 -> tie rounds up to 1
            [3, '50.00'],      // raw 1.5 -> tie rounds up to 2
            [1, '49.00'],      // raw 0.49 -> rounds down to 0
            [1, '150.00'],     // raw 1.5 -> 2, clamped to subtotal 1
            [1000, '0.00'],    // 0% -> fee 0
            [1000, '100.00'],  // 100% -> fee == subtotal
            [1000, '250.00'],  // >100% -> clamped to subtotal
            [7, '33.33'],      // raw 2.33331 -> rounds to 2
            [200, '2.50'],     // raw 5.0 -> exact
            [99, '12.34'],     // raw 12.2166 -> rounds to 12
        ];

        foreach ($boundaryCases as [$subtotal, $percent]) {
            $fee = $service->calculate($subtotal, $percent, Company::FEE_MODE_ABSORB)->applicationFee;

            $this->assertSame(
                $this->expectedApplicationFee($subtotal, $percent),
                $fee,
                sprintf('Boundary case subtotal=%d percent=%s', $subtotal, $percent)
            );
        }

        // Randomised exploration. Percents span 0..300% at hundredth
        // granularity so >100% values regularly force the clamp; subtotals
        // stay small enough that many (subtotal, percent) pairs land on
        // round-half-up ties.
        $this->forAll(
            Generator\choose(0, 1_000_000),        // subtotal in minor units
            Generator\choose(0, 30_000)            // percent in hundredths (0.00%..300.00%)
        )
            ->then(function (int $subtotal, int $percentHundredths) use ($service): void {
                // Render the percent as a DECIMAL(5,2)-style string, e.g. 1234 -> "12.34".
                $percent = number_format($percentHundredths / 100, 2, '.', '');

                $expected = $this->expectedApplicationFee($subtotal, $percent);
                $fee = $service->calculate($subtotal, $percent, Company::FEE_MODE_ABSORB)->applicationFee;

                $this->assertSame(
                    $expected,
                    $fee,
                    sprintf('subtotal=%d percent=%s', $subtotal, $percent)
                );

                // Clamp invariant: the fee is always within [0, subtotal].
                $this->assertGreaterThanOrEqual(0, $fee, 'Application_Fee must be >= 0.');
                $this->assertLessThanOrEqual($subtotal, $fee, 'Application_Fee must be <= subtotal.');
            });
    }

    /**
     * Property 16 (selection half): the effective percent is the Company's
     * `company_fee_percent` override when set, otherwise the platform-wide
     * Global_Fee_Percent (Requirements 12.3, 12.4, 20.5, 20.6). Verified end to
     * end: calculateForCompany must equal calculate() run with the selected
     * percent.
     *
     * **Validates: Requirements 12.3, 12.4, 20.5, 20.6**
     */
    // Feature: event-ticketing-platform, Property 16: effective percent selection — company override else global; calculateForCompany equals calculate() with the selected percent
    public function test_effective_percent_selects_company_override_else_global(): void
    {
        $service = new FeeCalculationService();

        $this->forAll(
            Generator\choose(0, 1_000_000),   // subtotal
            Generator\choose(0, 30_000),      // global percent in hundredths
            // 0 marks "no override"; anything else is the override in hundredths.
            Generator\choose(0, 30_000),
            Generator\bool()                  // whether an override is set
        )
            ->then(function (int $subtotal, int $globalHundredths, int $overrideHundredths, bool $hasOverride) use ($service): void {
                $globalPercent = number_format($globalHundredths / 100, 2, '.', '');
                $overridePercent = number_format($overrideHundredths / 100, 2, '.', '');

                PlatformSetting::query()->delete();
                PlatformSetting::factory()->create(['global_fee_percent' => $globalPercent]);

                $company = Company::factory()->create([
                    'fee_handling_mode' => Company::FEE_MODE_ABSORB,
                    'company_fee_percent' => $hasOverride ? $overridePercent : null,
                ]);

                $selectedPercent = $hasOverride ? $overridePercent : $globalPercent;

                // effectivePercent() picks the right source.
                $this->assertSame(
                    $this->expectedApplicationFee($subtotal, $selectedPercent),
                    $service->calculateForCompany($company, $subtotal)->applicationFee,
                    sprintf(
                        'subtotal=%d hasOverride=%s override=%s global=%s',
                        $subtotal,
                        $hasOverride ? 'true' : 'false',
                        $overridePercent,
                        $globalPercent
                    )
                );

                // And it agrees with calling calculate() using that same percent.
                $this->assertSame(
                    $service->calculate($subtotal, $selectedPercent, Company::FEE_MODE_ABSORB)->applicationFee,
                    $service->calculateForCompany($company, $subtotal)->applicationFee,
                    'calculateForCompany must match calculate() with the selected percent.'
                );
            });
    }
}
