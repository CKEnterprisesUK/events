<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 9.1 — the platform_settings table/model (global_fee_percent),
 * confirmation of companies fee fields, and the Owner-settable
 * Fee_Handling_Mode.
 *
 * Requirements: 13.1 (fee mode is absorb|pass_on), 13.2 (defaults to absorb),
 * 13.3 (Owner may set the mode), 20.5/20.6 (global_fee_percent as the default
 * applied when a Company has no override).
 */
class PlatformSettingsAndFeeHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_settings_row_persists_default_global_fee_percent(): void
    {
        // Requirement 20.5 — the single row carries the default Platform fee %.
        $setting = PlatformSetting::create([]);

        $this->assertSame(
            PlatformSetting::DEFAULT_GLOBAL_FEE_PERCENT,
            $setting->fresh()->global_fee_percent
        );
    }

    public function test_platform_settings_round_trips_a_custom_global_fee_percent(): void
    {
        $setting = PlatformSetting::create(['global_fee_percent' => '7.25']);

        $this->assertSame('7.25', $setting->fresh()->global_fee_percent);
    }

    public function test_current_returns_the_single_settings_row_and_creates_it_once(): void
    {
        $first = PlatformSetting::current();
        $second = PlatformSetting::current();

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PlatformSetting::query()->count());
    }

    public function test_company_defaults_fee_handling_mode_to_absorb(): void
    {
        // Requirement 13.2 — mode defaults to absorb when unset.
        $company = Company::create(['name' => 'Default Co', 'slug' => 'default-co']);

        $this->assertSame(Company::FEE_MODE_ABSORB, $company->fresh()->fee_handling_mode);
    }

    public function test_owner_can_set_fee_handling_mode_to_pass_on(): void
    {
        // Requirement 13.3 — the Company's mode is settable (Owner-gated at call
        // site); 13.1 — pass_on is a valid mode.
        $company = Company::create(['name' => 'Switch Co', 'slug' => 'switch-co']);

        $company->setFeeHandlingMode(Company::FEE_MODE_PASS_ON);

        $this->assertSame(Company::FEE_MODE_PASS_ON, $company->fresh()->fee_handling_mode);
    }

    public function test_owner_can_switch_fee_handling_mode_back_to_absorb(): void
    {
        $company = Company::create([
            'name' => 'Back Co',
            'slug' => 'back-co',
            'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
        ]);

        $company->setFeeHandlingMode(Company::FEE_MODE_ABSORB);

        $this->assertSame(Company::FEE_MODE_ABSORB, $company->fresh()->fee_handling_mode);
    }

    public function test_setting_an_unsupported_fee_handling_mode_is_rejected(): void
    {
        // Requirement 13.1 — only absorb|pass_on are supported.
        $company = Company::create(['name' => 'Bad Co', 'slug' => 'bad-co']);

        $this->expectException(InvalidArgumentException::class);

        try {
            $company->setFeeHandlingMode('waive');
        } finally {
            // The invalid write leaves the stored mode unchanged.
            $this->assertSame(Company::FEE_MODE_ABSORB, $company->fresh()->fee_handling_mode);
        }
    }

    public function test_company_fee_percent_override_is_null_by_default(): void
    {
        // Requirement 20.6 — NULL override means fall back to the global fee.
        $company = Company::create(['name' => 'Nullfee Co', 'slug' => 'nullfee-co']);

        $this->assertNull($company->fresh()->company_fee_percent);
    }
}
