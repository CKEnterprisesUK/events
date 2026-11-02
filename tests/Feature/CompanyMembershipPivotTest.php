<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app
 *
 * Covers task 2.2 — the `company_user` membership pivot and the
 * `User::companies()` BelongsToMany relation introduced in task 2.1.
 *
 * The pivot turns the single-Company `users.company_id` into a many-to-many
 * membership (Requirement 4.2) so a Staff_User can belong to — and switch
 * between — more than one Company from the iOS app. Each membership carries the
 * user's `role` WITHIN that Company on the pivot, and the migration backfills
 * one membership per existing Company_User mirroring their `(company_id, role)`
 * so current users keep working the moment the API reads memberships.
 *
 * Requirements: 4.2
 */
class CompanyMembershipPivotTest extends TestCase
{
    use RefreshDatabase;

    public function test_companies_relation_returns_attached_company_with_pivot_role(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->admin()->create();

        // Mirror the membership the migration's backfill would seed for this
        // user: one row carrying their (company_id, role).
        $user->companies()->attach($company->id, ['role' => $user->role]);

        $companies = $user->companies()->get();

        $this->assertCount(1, $companies);

        $related = $companies->first();
        $this->assertTrue($company->is($related));
        // The per-Company role is carried on the pivot (role is a property of
        // the membership, not of the user globally).
        $this->assertSame(User::ROLE_ADMIN, $related->pivot->role);
        // withTimestamps() exposes when the membership was created.
        $this->assertNotNull($related->pivot->created_at);
    }

    public function test_user_can_belong_to_more_than_one_company_via_the_pivot(): void
    {
        $home = Company::factory()->create();
        $other = Company::factory()->create();

        $user = User::factory()->for($home)->owner()->create();

        $user->companies()->attach($home->id, ['role' => User::ROLE_OWNER]);
        $user->companies()->attach($other->id, ['role' => User::ROLE_SCANNER]);

        $roleByCompanyId = $user->companies()
            ->get()
            ->mapWithKeys(fn (Company $c) => [$c->id => $c->pivot->role])
            ->all();

        $this->assertCount(2, $roleByCompanyId);
        // The role differs per membership — it is scoped to each Company.
        $this->assertSame(User::ROLE_OWNER, $roleByCompanyId[$home->id]);
        $this->assertSame(User::ROLE_SCANNER, $roleByCompanyId[$other->id]);
    }

    public function test_backfill_seeds_one_membership_mirroring_company_id_and_role_per_user(): void
    {
        // Create a spread of Company_Users across two Companies with varying
        // roles, plus a Super_Admin that must be skipped by the backfill.
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $owner = User::factory()->for($companyA)->owner()->create();
        $admin = User::factory()->for($companyA)->admin()->create();
        $scanner = User::factory()->for($companyB)->scanner()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        // Run the exact backfill the migration performs (seed one membership
        // row from each existing Company_User's current (company_id, role)).
        $this->runMembershipBackfill();

        // Every Company_User gained exactly one membership mirroring their
        // (company_id, role).
        foreach ([$owner, $admin, $scanner] as $user) {
            $memberships = $user->companies()->get();

            $this->assertCount(
                1,
                $memberships,
                "User {$user->id} should gain exactly one membership."
            );

            $membership = $memberships->first();
            $this->assertSame($user->company_id, $membership->id);
            $this->assertSame($user->role, $membership->pivot->role);
        }

        // The Super_Admin (NULL company_id/role) is not part of any Company's
        // membership and must be skipped.
        $this->assertCount(0, $superAdmin->companies()->get());

        // Exactly three membership rows total — one per Company_User, none for
        // the skipped Super_Admin.
        $this->assertSame(3, DB::table('company_user')->count());
    }

    public function test_backfill_is_idempotent_and_does_not_duplicate_memberships(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->admin()->create();

        // Running the backfill twice must not create a second membership row
        // for the same (company_id, user_id) thanks to the unique index +
        // insertOrIgnore.
        $this->runMembershipBackfill();
        $this->runMembershipBackfill();

        $this->assertSame(
            1,
            DB::table('company_user')
                ->where('user_id', $user->id)
                ->where('company_id', $company->id)
                ->count()
        );
    }

    /**
     * Seed one membership row for every existing Company_User, mirroring their
     * current `(company_id, role)` and skipping Super_Admins — the same
     * idempotent backfill the `company_user` migration runs. Reproduced here
     * because under RefreshDatabase the migration's own backfill runs against an
     * empty `users` table, so it cannot be exercised against rows created by
     * the test.
     */
    private function runMembershipBackfill(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotNull('company_id')
            ->whereNotNull('role')
            ->orderBy('id')
            ->select(['id', 'company_id', 'role'])
            ->chunkById(500, function ($users) use ($now) {
                $rows = $users->map(fn ($user) => [
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'role' => $user->role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('company_user')->insertOrIgnore($rows);
                }
            });
    }
}
