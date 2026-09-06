<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Rules\CompanySlug;
use App\Services\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 2.1 — the companies table/model, TenantContext, and slug
 * validation.
 *
 * Requirements: 1.1 (Company-owned records carry company_id / companies is the
 * tenant root), 1.6 (slug uniqueness/format), 2.x (status enum), 13.1/13.2
 * (fee_handling_mode default absorb).
 */
class CompanyTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_persists_all_design_columns_with_defaults(): void
    {
        $company = Company::create([
            'name' => 'Acme Charity',
            'slug' => 'acme-charity',
        ]);

        $fresh = $company->fresh();

        $this->assertSame('Acme Charity', $fresh->name);
        $this->assertSame('acme-charity', $fresh->slug);
        // Requirement 2.x — status defaults to active.
        $this->assertSame(Company::STATUS_ACTIVE, $fresh->status);
        // Requirements 13.1, 13.2 — fee handling mode defaults to absorb.
        $this->assertSame(Company::FEE_MODE_ABSORB, $fresh->fee_handling_mode);
        // Requirements 12.3/12.4 — NULL override means use the global fee.
        $this->assertNull($fresh->company_fee_percent);
        $this->assertNull($fresh->stripe_account_id);
        $this->assertFalse($fresh->stripe_charges_enabled);
        $this->assertSame('GBP', $fresh->currency);
    }

    public function test_company_round_trips_branding_and_fee_fields(): void
    {
        $company = Company::create([
            'name' => 'Bright Events',
            'slug' => 'bright-events',
            'status' => Company::STATUS_SUSPENDED,
            'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
            'company_fee_percent' => '2.50',
            'stripe_account_id' => 'acct_123',
            'stripe_charges_enabled' => true,
            'currency' => 'USD',
            'primary_colour' => '#ff8800',
            'logo_path' => 'logos/bright.png',
            'terms_text' => 'Please arrive early.',
            'ticket_field_defs' => ['fields' => [['key' => 'seat', 'label' => 'Seat']]],
        ]);

        $fresh = $company->fresh();

        $this->assertTrue($fresh->isSuspended());
        $this->assertSame(Company::FEE_MODE_PASS_ON, $fresh->fee_handling_mode);
        $this->assertSame('2.50', $fresh->company_fee_percent);
        $this->assertSame('acct_123', $fresh->stripe_account_id);
        $this->assertTrue($fresh->stripe_charges_enabled);
        $this->assertSame('USD', $fresh->currency);
        $this->assertSame('#ff8800', $fresh->primary_colour);
        $this->assertSame('logos/bright.png', $fresh->logo_path);
        $this->assertSame('Please arrive early.', $fresh->terms_text);
        $this->assertSame(
            ['fields' => [['key' => 'seat', 'label' => 'Seat']]],
            $fresh->ticket_field_defs
        );
    }

    public function test_slug_is_unique_at_the_database_level(): void
    {
        Company::create(['name' => 'First', 'slug' => 'shared-slug']);

        $this->expectException(QueryException::class);

        Company::create(['name' => 'Second', 'slug' => 'shared-slug']);
    }

    public function test_tenant_context_starts_empty(): void
    {
        $context = app(TenantContext::class);

        $this->assertFalse($context->hasCompany());
        $this->assertNull($context->company());
        $this->assertNull($context->companyId());
    }

    public function test_tenant_context_exposes_resolved_company_id(): void
    {
        $company = Company::create(['name' => 'Resolved', 'slug' => 'resolved']);

        $context = app(TenantContext::class);
        $context->setCompany($company);

        $this->assertTrue($context->hasCompany());
        $this->assertSame($company->id, $context->companyId());
        $this->assertTrue($company->is($context->company()));

        $context->clear();
        $this->assertFalse($context->hasCompany());
        $this->assertNull($context->companyId());
    }

    public function test_tenant_context_is_a_request_lifetime_singleton(): void
    {
        $this->assertSame(app(TenantContext::class), app(TenantContext::class));
    }

    public function test_slug_rule_accepts_valid_unique_slug(): void
    {
        $validator = Validator::make(
            ['slug' => 'valid-slug-123'],
            ['slug' => [new CompanySlug]]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_slug_rule_rejects_uppercase_and_invalid_characters(): void
    {
        // Note: a blank/absent slug is a `required` concern (Laravel skips
        // non-implicit rules for empty values); the format helper test covers
        // the empty-string case directly.
        foreach (['Invalid', 'has space', 'under_score', 'dot.com'] as $candidate) {
            $validator = Validator::make(
                ['slug' => $candidate],
                ['slug' => [new CompanySlug]]
            );

            $this->assertTrue(
                $validator->fails(),
                "Slug '{$candidate}' should be rejected."
            );
        }
    }

    public function test_slug_rule_rejects_slug_over_255_characters(): void
    {
        $validator = Validator::make(
            ['slug' => str_repeat('a', 256)],
            ['slug' => [new CompanySlug]]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_slug_rule_accepts_slug_of_exactly_255_characters(): void
    {
        $validator = Validator::make(
            ['slug' => str_repeat('a', 255)],
            ['slug' => [new CompanySlug]]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_slug_rule_rejects_duplicate_slug(): void
    {
        Company::create(['name' => 'Taken', 'slug' => 'taken-slug']);

        $validator = Validator::make(
            ['slug' => 'taken-slug'],
            ['slug' => [new CompanySlug]]
        );

        $this->assertTrue($validator->fails());
    }

    public function test_slug_rule_can_ignore_the_owning_company_on_update(): void
    {
        $company = Company::create(['name' => 'Owner', 'slug' => 'owner-slug']);

        $validator = Validator::make(
            ['slug' => 'owner-slug'],
            ['slug' => [new CompanySlug($company->id)]]
        );

        $this->assertTrue($validator->passes());
    }

    public function test_is_valid_format_helper_matches_the_rule_semantics(): void
    {
        $this->assertTrue(CompanySlug::isValidFormat('good-slug'));
        $this->assertTrue(CompanySlug::isValidFormat('a'));
        $this->assertTrue(CompanySlug::isValidFormat(str_repeat('a', 255)));

        $this->assertFalse(CompanySlug::isValidFormat(''));
        $this->assertFalse(CompanySlug::isValidFormat('Upper'));
        $this->assertFalse(CompanySlug::isValidFormat('has space'));
        $this->assertFalse(CompanySlug::isValidFormat(str_repeat('a', 256)));
    }
}
