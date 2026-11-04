<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferralCodeTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Events',
            'slug' => 'acme-events',
            'legal_name' => 'Acme Events Ltd',
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'organisation_email' => 'hello@acme.test',
            'address_line_1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'name' => 'Olivia Owner',
            'email' => 'olivia@acme.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'agree_terms' => '1',
        ], $overrides);
    }

    public function test_super_admin_can_create_a_code(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post(route('admin.referral-codes.store'), [
            'code' => 'friend5', 'fee_percent' => '2.50',
        ])->assertRedirect(route('admin.referral-codes.index'));

        $this->assertDatabaseHas('referral_codes', ['code' => 'FRIEND5', 'fee_percent' => '2.50']);
    }

    public function test_non_super_admin_cannot_create_a_code(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('admin.referral-codes.store'), [
            'code' => 'NOPE', 'fee_percent' => '1',
        ])->assertForbidden();

        $this->assertDatabaseMissing('referral_codes', ['code' => 'NOPE']);
    }

    public function test_signup_with_code_sets_company_fee_and_counts_use(): void
    {
        $code = ReferralCode::create(['code' => 'FRIEND5', 'fee_percent' => '2.50']);

        $this->post('/register', $this->payload(['referral_code' => 'friend5']))->assertRedirect();

        $company = Company::where('slug', 'acme-events')->firstOrFail();
        $this->assertSame('2.50', $company->company_fee_percent);
        $this->assertSame($code->id, $company->referral_code_id);
        $this->assertSame(1, $code->fresh()->uses_count);
    }

    public function test_invalid_disabled_or_exhausted_code_is_rejected(): void
    {
        ReferralCode::create(['code' => 'OFF', 'fee_percent' => '1', 'is_active' => false]);
        ReferralCode::create(['code' => 'FULL', 'fee_percent' => '1', 'max_uses' => 1, 'uses_count' => 1]);

        foreach (['NOSUCH', 'OFF', 'FULL'] as $bad) {
            $this->post('/register', $this->payload(['referral_code' => $bad]))
                ->assertSessionHasErrors('referral_code');
        }
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_ref_query_parameter_prefills_the_form(): void
    {
        $this->get('/register?ref=friend5')->assertOk()->assertSee('value="FRIEND5"', false);
        $this->get('/register')->assertOk()->assertSee('value="FRIEND5"', false);
    }

    public function test_signup_without_code_is_unchanged(): void
    {
        $this->post('/register', $this->payload())->assertRedirect();

        $this->assertNull(Company::where('slug', 'acme-events')->firstOrFail()->company_fee_percent);
    }
}
