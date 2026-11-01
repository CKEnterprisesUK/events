<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\Onboarding\OnboardingChecklist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — data-retention onboarding step.
 *
 * The onboarding checklist gains a mandatory "Set data retention" step. It is
 * ADVISORY (it nudges on the dashboard until done, like the other steps) and
 * completes only once the Owner has EXPLICITLY confirmed a retention choice —
 * the safe 24-month default alone does not count, so the step reflects a
 * deliberate decision rather than an untouched default.
 */
class RetentionOnboardingStepTest extends TestCase
{
    use RefreshDatabase;

    private function orgFields(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Acme Events Ltd',
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'email' => 'hello@acme.test',
            'address_line_1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'GB',
            'retention_months' => 24,
        ], $overrides);
    }

    public function test_retention_step_is_incomplete_until_explicitly_confirmed(): void
    {
        // A company with the default window but no explicit confirmation: the
        // step must still be incomplete.
        $company = Company::factory()->create([
            'retention_months' => null,
            'retention_confirmed_at' => null,
        ]);

        $progress = app(OnboardingChecklist::class)->for($company);

        $retention = collect($progress->steps())->firstWhere('key', 'retention');
        $this->assertNotNull($retention);
        $this->assertFalse($retention->complete);
    }

    public function test_retention_step_completes_once_confirmed(): void
    {
        $company = Company::factory()->create([
            'retention_months' => 24,
            'retention_confirmed_at' => now(),
        ]);

        $progress = app(OnboardingChecklist::class)->for($company);

        $retention = collect($progress->steps())->firstWhere('key', 'retention');
        $this->assertTrue($retention->complete);
    }

    public function test_saving_the_branding_form_confirms_retention(): void
    {
        $owner = User::factory()->owner()->create();
        $this->assertNull($owner->company->retention_confirmed_at);

        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'retention_months' => 36,
        ]))->assertRedirect(route('dashboard.branding.edit'));

        $company = $owner->company->fresh();
        $this->assertSame(36, $company->retention_months);
        $this->assertNotNull($company->retention_confirmed_at);
        $this->assertTrue($company->hasConfirmedRetention());
    }

    public function test_retention_below_the_floor_is_rejected(): void
    {
        $owner = User::factory()->owner()->create();

        // Below the 6-month floor — rejected so a company cannot scrub data
        // still inside the refund/dispute horizon.
        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'retention_months' => 1,
        ]))->assertSessionHasErrors('retention_months');
    }

    public function test_retention_confirmation_time_is_preserved_across_saves(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'retention_months' => 24,
        ]))->assertRedirect();
        $firstConfirmedAt = $owner->company->fresh()->retention_confirmed_at;

        // A later save changing the window keeps the ORIGINAL confirmation time.
        $this->travel(2)->days();
        $this->actingAs($owner)->put(route('dashboard.branding.update'), $this->orgFields([
            'retention_months' => 60,
        ]))->assertRedirect();

        $company = $owner->company->fresh();
        $this->assertSame(60, $company->retention_months);
        $this->assertEquals(
            $firstConfirmedAt->toIso8601String(),
            $company->retention_confirmed_at->toIso8601String(),
        );
    }
}
