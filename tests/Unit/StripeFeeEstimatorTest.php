<?php

namespace Tests\Unit;

use App\Services\StripeFeeEstimator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see StripeFeeEstimator::estimate()} — the pure, primitive
 * estimate of Stripe's card-processing fee used by the calculator/preview. No
 * database or framework state is touched. (Configurable-estimate feature)
 */
class StripeFeeEstimatorTest extends TestCase
{
    private StripeFeeEstimator $estimator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->estimator = new StripeFeeEstimator;
    }

    public function test_estimate_is_percent_plus_fixed(): void
    {
        // £100.00 at 1.5% + £0.20 = 150 + 20 = 170 pence.
        $this->assertSame(170, $this->estimator->estimate(10_000, '1.50', 20));
    }

    public function test_percent_component_rounds_half_up(): void
    {
        // 333 × 1.5% = 4.995 → half-up 5; plus fixed 20 = 25.
        $this->assertSame(25, $this->estimator->estimate(333, '1.50', 20));
    }

    public function test_zero_amount_incurs_no_fee(): void
    {
        // A free order is never charged, so Stripe takes nothing — not even the
        // fixed part.
        $this->assertSame(0, $this->estimator->estimate(0, '1.50', 20));
        $this->assertSame(0, $this->estimator->estimate(-500, '1.50', 20));
    }

    public function test_zero_rates_yield_zero(): void
    {
        $this->assertSame(0, $this->estimator->estimate(10_000, '0', 0));
    }

    public function test_fixed_only_estimate(): void
    {
        $this->assertSame(20, $this->estimator->estimate(10_000, '0', 20));
    }
}
