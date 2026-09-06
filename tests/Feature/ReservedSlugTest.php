<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\ReservedSlug;
use App\Models\User;
use App\Rules\CompanySlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * The Company_Slug blocklist: reserved slugs that a Company may never claim at
 * self-signup or on a slug change (reserved platform routes / infra paths that
 * path-based tenancy would otherwise shadow, plus brand/abuse words), enforced
 * by {@see CompanySlug} and managed by a Super_Admin on the `/admin` surface.
 */
class ReservedSlugTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the CompanySlug rule against a candidate and return the validator.
     */
    private function validateSlug(string $slug): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make(['slug' => $slug], ['slug' => [new CompanySlug]]);
    }

    // ---- Rule enforcement ----------------------------------------------------

    public function test_a_reserved_slug_is_rejected_by_the_company_slug_rule(): void
    {
        ReservedSlug::create(['slug' => 'admin', 'is_system' => true]);

        $validator = $this->validateSlug('admin');

        $this->assertTrue($validator->fails());
        $this->assertSame('The slug is not available.', $validator->errors()->first('slug'));
    }

    public function test_reserved_check_is_case_insensitive(): void
    {
        ReservedSlug::create(['slug' => 'support', 'is_system' => false]);

        // The slug format itself is lowercase-only, but the reserved lookup
        // normalises so mixed-case operator entries still match.
        $this->assertTrue(ReservedSlug::isReserved('SUPPORT'));
        $this->assertTrue(ReservedSlug::isReserved('Support'));
    }

    public function test_a_non_reserved_available_slug_passes(): void
    {
        ReservedSlug::create(['slug' => 'admin', 'is_system' => true]);

        $this->assertFalse($this->validateSlug('acme-events')->fails());
    }

    public function test_reserved_takes_precedence_but_format_still_wins_first(): void
    {
        // An invalid format is reported as a format error, not "not available",
        // even if it would also be a reserved value.
        $validator = $this->validateSlug('Admin');

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString(
            'lowercase letters',
            $validator->errors()->first('slug')
        );
    }

    public function test_registration_rejects_a_reserved_slug(): void
    {
        ReservedSlug::create(['slug' => 'admin', 'is_system' => true]);

        $payload = [
            'company_name' => 'Admin Co',
            'slug' => 'admin',
            'legal_name' => 'Admin Co Ltd',
            'organisation_type' => Company::TYPE_COMPANY,
            'company_number' => '01234567',
            'organisation_email' => 'hello@admin.test',
            'address_line_1' => '1 High Street',
            'city' => 'London',
            'postcode' => 'EC1A 1BB',
            'country' => 'gb',
            'name' => 'Olivia Owner',
            'email' => 'olivia@admin.test',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'agree_terms' => '1',
        ];

        $this->from('/register')->post('/register', $payload)
            ->assertRedirect('/register')
            ->assertSessionHasErrors('slug');

        $this->assertDatabaseMissing('companies', ['slug' => 'admin']);
        $this->assertDatabaseMissing('users', ['email' => 'olivia@admin.test']);
    }

    // ---- Model helpers -------------------------------------------------------

    public function test_ensure_system_defaults_seeds_and_is_idempotent(): void
    {
        ReservedSlug::ensureSystemDefaults();
        $first = ReservedSlug::count();

        $this->assertGreaterThan(0, $first);
        $this->assertTrue(ReservedSlug::isReserved('login'));
        $this->assertTrue(ReservedSlug::isReserved('dashboard'));

        // Re-running does not duplicate rows.
        ReservedSlug::ensureSystemDefaults();
        $this->assertSame($first, ReservedSlug::count());
    }

    // ---- Super-admin management ----------------------------------------------

    public function test_super_admin_can_view_the_blocklist_with_defaults_seeded(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->get(route('admin.reserved-slugs.index'))
            ->assertOk()
            ->assertSee('login')
            ->assertSee('dashboard');

        $this->assertDatabaseHas('reserved_slugs', ['slug' => 'login', 'is_system' => true]);
    }

    public function test_super_admin_can_add_a_reserved_slug(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)->post(route('admin.reserved-slugs.store'), [
            'slug' => 'coca-cola',
            'reason' => 'Trademark',
        ])->assertRedirect(route('admin.reserved-slugs.index'));

        $this->assertDatabaseHas('reserved_slugs', [
            'slug' => 'coca-cola',
            'reason' => 'Trademark',
            'is_system' => false,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reserved_slug.added']);
    }

    public function test_adding_normalises_case_and_rejects_bad_format(): void
    {
        $admin = User::factory()->superAdmin()->create();

        // Mixed case is normalised to lowercase.
        $this->actingAs($admin)->post(route('admin.reserved-slugs.store'), [
            'slug' => 'BrandName',
        ])->assertRedirect(route('admin.reserved-slugs.index'));
        $this->assertDatabaseHas('reserved_slugs', ['slug' => 'brandname']);

        // Values that could never be a valid slug are rejected.
        $this->actingAs($admin)->from(route('admin.reserved-slugs.index'))
            ->post(route('admin.reserved-slugs.store'), ['slug' => 'has spaces'])
            ->assertSessionHasErrors('slug');
    }

    public function test_adding_a_duplicate_is_rejected(): void
    {
        $admin = User::factory()->superAdmin()->create();
        ReservedSlug::create(['slug' => 'taken', 'is_system' => false]);

        $this->actingAs($admin)->from(route('admin.reserved-slugs.index'))
            ->post(route('admin.reserved-slugs.store'), ['slug' => 'taken'])
            ->assertSessionHasErrors('slug');
    }

    public function test_super_admin_can_remove_a_custom_slug_but_not_a_system_slug(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $custom = ReservedSlug::create(['slug' => 'removable', 'is_system' => false]);
        $system = ReservedSlug::create(['slug' => 'admin', 'is_system' => true]);

        // Custom row is removed.
        $this->actingAs($admin)->delete(route('admin.reserved-slugs.destroy', $custom))
            ->assertRedirect(route('admin.reserved-slugs.index'));
        $this->assertDatabaseMissing('reserved_slugs', ['id' => $custom->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reserved_slug.removed']);

        // System row is protected.
        $this->actingAs($admin)->from(route('admin.reserved-slugs.index'))
            ->delete(route('admin.reserved-slugs.destroy', $system))
            ->assertSessionHasErrors();
        $this->assertDatabaseHas('reserved_slugs', ['id' => $system->id]);
    }

    public function test_non_super_admin_cannot_manage_the_blocklist(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get(route('admin.reserved-slugs.index'))->assertForbidden();
        $this->actingAs($owner)->post(route('admin.reserved-slugs.store'), ['slug' => 'x'])->assertForbidden();
    }
}
