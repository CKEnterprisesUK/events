<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 2.3 — the ResolveTenant and EnforceTenantScope middleware and the
 * global company_id scope carried by the BelongsToCompany trait.
 *
 * Requirements:
 *  - 1.2 case-insensitive slug resolution
 *  - 1.3 404 + no active Company on unmatched slug
 *  - 1.4 global scope constrains queries to the resolved company_id
 *  - 1.5 cross-Company access denied (not found), record unchanged
 *  - 1.7 no slug segment / reserved prefix establishes no Company + denies
 *        Company-owned records
 *  - 2.1, 2.2 suspended Company storefront/event returns 404
 */
class TenantResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The `widgets` helper table is provided by the testing-only
        // create_test_support_tables migration (no runtime DDL here, so the
        // RefreshDatabase transaction stays intact).

        // Routes that live inside the tenant group so the middleware runs.
        Route::middleware('tenant')->group(function () {
            Route::get('/{companySlug}/widgets', function (TenantContext $ctx) {
                return response()->json([
                    'company_id' => $ctx->companyId(),
                    'labels' => Widget::query()->pluck('label'),
                ]);
            })->where('companySlug', '[A-Za-z0-9-]+');
        });
    }

    public function test_resolves_company_by_exact_slug(): void
    {
        $company = Company::factory()->create(['slug' => 'acme']);
        Widget::withoutGlobalScopes()->create(['company_id' => $company->id, 'label' => 'a']);

        $response = $this->getJson('/acme/widgets');

        $response->assertOk();
        $response->assertJsonPath('company_id', $company->id);
    }

    public function test_resolves_company_case_insensitively(): void
    {
        // Requirement 1.2 — a valid slug resolves in any letter-case.
        $company = Company::factory()->create(['slug' => 'bright-events']);

        $this->getJson('/Bright-Events/widgets')
            ->assertOk()
            ->assertJsonPath('company_id', $company->id);

        $this->getJson('/BRIGHT-EVENTS/widgets')
            ->assertOk()
            ->assertJsonPath('company_id', $company->id);
    }

    public function test_unmatched_slug_returns_404(): void
    {
        // Requirement 1.3 — no Company matches → 404, no active Company.
        $this->getJson('/no-such-company/widgets')->assertNotFound();
    }

    public function test_suspended_company_returns_404(): void
    {
        // Requirements 2.1, 2.2 — suspended Company storefront/event is 404.
        Company::factory()->suspended()->create(['slug' => 'suspended-co']);

        $this->getJson('/suspended-co/widgets')->assertNotFound();
    }

    public function test_global_scope_limits_reads_to_resolved_company(): void
    {
        // Requirement 1.4 — reads only return the resolved Company's rows.
        $resolved = Company::factory()->create(['slug' => 'resolved-co']);
        $other = Company::factory()->create(['slug' => 'other-co']);

        Widget::withoutGlobalScopes()->create(['company_id' => $resolved->id, 'label' => 'mine']);
        Widget::withoutGlobalScopes()->create(['company_id' => $other->id, 'label' => 'theirs']);

        $response = $this->getJson('/resolved-co/widgets');

        $response->assertOk();
        $response->assertJsonPath('labels', ['mine']);
    }

    public function test_cross_company_row_is_not_found_and_unchanged(): void
    {
        // Requirement 1.5 — a row owned by another Company never matches under
        // the resolved tenant and is left unchanged.
        $resolved = Company::factory()->create(['slug' => 'resolved-co']);
        $other = Company::factory()->create(['slug' => 'other-co']);

        $foreign = Widget::withoutGlobalScopes()->create([
            'company_id' => $other->id,
            'label' => 'foreign',
        ]);

        app(TenantContext::class)->setCompany($resolved);

        $this->assertNull(Widget::query()->find($foreign->id));

        // An update targeted by id under the scope affects no rows.
        $affected = Widget::query()->where('id', $foreign->id)->update(['label' => 'hijacked']);
        $this->assertSame(0, $affected);

        $this->assertSame('foreign', $foreign->fresh()->label);

        app(TenantContext::class)->clear();
    }

    public function test_belongs_to_company_autofills_company_id_on_create(): void
    {
        // Requirement 1.1 — new Company-owned rows belong to the active Company.
        $resolved = Company::factory()->create(['slug' => 'resolved-co']);

        app(TenantContext::class)->setCompany($resolved);

        $widget = Widget::query()->create(['label' => 'auto']);

        $this->assertSame($resolved->id, $widget->company_id);

        app(TenantContext::class)->clear();
    }

    public function test_no_active_company_denies_company_owned_records(): void
    {
        // Requirement 1.7 — with no resolved Company, the scope matches nothing.
        $company = Company::factory()->create(['slug' => 'acme']);
        Widget::withoutGlobalScopes()->create(['company_id' => $company->id, 'label' => 'x']);

        // TenantContext is empty here (no request/middleware ran).
        $this->assertCount(0, Widget::query()->get());
    }

    public function test_context_is_cleared_after_the_request(): void
    {
        // EnforceTenantScope clears the tenant at the end of the request so it
        // never leaks into subsequent work on the same worker.
        $company = Company::factory()->create(['slug' => 'acme']);

        $this->getJson('/acme/widgets')->assertOk();

        $this->assertFalse(app(TenantContext::class)->hasCompany());
    }
}

/**
 * Test-only Company-owned model. Uses the production trait so these tests
 * exercise the real global scope and auto-fill behaviour.
 */
class Widget extends Model
{
    use BelongsToCompany;

    protected $table = 'widgets';

    protected $guarded = [];
}
