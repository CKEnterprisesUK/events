<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: capture and manage the organisation's legal/registration details.
 *
 * The legal team requires each Company to record its registered name,
 * organisation type, main contact, registered address and (where applicable)
 * its Companies House / Charity Commission number. These are captured at signup
 * (RegisterController) and maintained afterwards on the Owner-gated branding /
 * settings surface (BrandingController).
 */
class CompanyLegalDetailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A complete, valid signup payload. Override individual keys per test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Acme Events',
            'slug' => 'acme-events',
            'legal_name' => 'Acme Events Ltd',
            'trading_name' => 'Acme',
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'website' => 'https://acme.test',
            'organisation_email' => 'hello@acme.test',
            'phone' => '+44 20 7946 0000',
            'address_line_1' => '1 High Street',
            'address_line_2' => 'Suite 2',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'gb',
            'name' => 'Olivia Owner',
            'email' => 'olivia@acme.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ], $overrides);
    }

    public function test_signup_captures_the_legal_details_on_the_company(): void
    {
        $response = $this->post('/register', $this->signupPayload());

        $response->assertRedirect('/dashboard');

        $company = Company::query()->where('slug', 'acme-events')->firstOrFail();

        $this->assertSame('Acme Events Ltd', $company->legal_name);
        $this->assertSame('Acme', $company->trading_name);
        $this->assertSame(Company::TYPE_COMPANY, $company->organisation_type);
        $this->assertSame('01234567', $company->company_number);
        $this->assertNull($company->charity_number);
        $this->assertSame('https://acme.test', $company->website);
        $this->assertSame('hello@acme.test', $company->email);
        $this->assertSame('+44 20 7946 0000', $company->phone);
        $this->assertSame('1 High Street', $company->address_line_1);
        $this->assertSame('Suite 2', $company->address_line_2);
        $this->assertSame('London', $company->city);
        $this->assertSame('EC1A 1BB', $company->postcode);
        // Country is normalised to an upper-case ISO code.
        $this->assertSame('GB', $company->country);
        // New Companies remain active (the suspension gates key off suspended).
        $this->assertSame(Company::STATUS_ACTIVE, $company->status);
    }

    public function test_signup_requires_the_mandatory_legal_fields(): void
    {
        $response = $this->from('/register')->post('/register', $this->signupPayload([
            'legal_name' => '',
            'organisation_type' => '',
            'organisation_email' => '',
            'address_line_1' => '',
            'city' => '',
            'postcode' => '',
        ]));

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors([
            'legal_name',
            'organisation_type',
            'organisation_email',
            'address_line_1',
            'city',
            'postcode',
        ]);

        $this->assertDatabaseCount('companies', 0);
    }

    public function test_signup_requires_a_charity_number_for_charities(): void
    {
        $response = $this->from('/register')->post('/register', $this->signupPayload([
            'organisation_type' => Company::TYPE_CHARITY,
            'company_number' => '',
            'charity_number' => '',
        ]));

        $response->assertSessionHasErrors('charity_number');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_signup_requires_a_company_number_for_cics(): void
    {
        $response = $this->from('/register')->post('/register', $this->signupPayload([
            'organisation_type' => Company::TYPE_CIC,
            'company_number' => '',
        ]));

        $response->assertSessionHasErrors('company_number');
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_owner_can_update_legal_details_on_the_branding_page(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->put(route('dashboard.branding.update'), [
            'legal_name' => 'Rebranded Events Ltd',
            'trading_name' => 'Rebranded',
            'organisation_type' => Company::TYPE_CHARITY,
            'charity_number' => '1122334',
            'website' => 'https://rebranded.test',
            'email' => 'contact@rebranded.test',
            'phone' => '01234 567890',
            'address_line_1' => '99 New Road',
            'city' => 'Manchester',
            'postcode' => 'M1 2AB',
            'country' => 'gb',
        ])->assertRedirect(route('dashboard.branding.edit'));

        $company = $owner->company->fresh();
        $this->assertSame('Rebranded Events Ltd', $company->legal_name);
        $this->assertSame('Rebranded', $company->trading_name);
        $this->assertSame(Company::TYPE_CHARITY, $company->organisation_type);
        $this->assertSame('1122334', $company->charity_number);
        $this->assertSame('contact@rebranded.test', $company->email);
        $this->assertSame('99 New Road', $company->address_line_1);
        $this->assertSame('Manchester', $company->city);
        $this->assertSame('M1 2AB', $company->postcode);
        $this->assertSame('GB', $company->country);
    }

    public function test_branding_edit_page_renders_the_legal_detail_fields(): void
    {
        $owner = User::factory()->owner()->create();
        $owner->company->update([
            'legal_name' => 'Displayed Legal Name Ltd',
            'address_line_1' => '7 Render Street',
        ]);

        $response = $this->actingAs($owner)->get(route('dashboard.branding.edit'));

        $response->assertOk();
        $response->assertSee('Displayed Legal Name Ltd', false);
        $response->assertSee('7 Render Street', false);
    }
}
