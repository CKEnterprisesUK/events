<?php

namespace Tests\Feature;

use App\Exceptions\RoleAssignmentException;
use App\Models\Company;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Exercises the RoleService single-Owner invariant and four-role enforcement
 * on assign / demote / remove / transfer. (Requirements 3.1, 3.2, 4.4, 4.5)
 * Universal coverage across operation sequences is added by the Property 5
 * property-based test (task 4.3); this file pins the concrete behaviours.
 */
class RoleServiceTest extends TestCase
{
    use RefreshDatabase;

    private RoleService $roles;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roles = app(RoleService::class);
        $this->company = Company::factory()->create();
    }

    private function user(string $role): User
    {
        return User::factory()->create([
            'company_id' => $this->company->id,
            'role' => $role,
        ]);
    }

    public function test_assigning_a_second_owner_is_rejected_and_owner_unchanged(): void
    {
        $owner = $this->user(User::ROLE_OWNER);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->expectException(RoleAssignmentException::class);

        try {
            $this->roles->assignRole($admin, User::ROLE_OWNER);
        } finally {
            $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
            $this->assertSame(User::ROLE_OWNER, $owner->fresh()->role);
            $this->assertSame(1, User::where('company_id', $this->company->id)->where('role', User::ROLE_OWNER)->count());
        }
    }

    public function test_demoting_the_last_owner_is_rejected(): void
    {
        $owner = $this->user(User::ROLE_OWNER);

        $this->expectException(RoleAssignmentException::class);

        try {
            $this->roles->demote($owner, User::ROLE_ADMIN);
        } finally {
            $this->assertSame(User::ROLE_OWNER, $owner->fresh()->role);
        }
    }

    public function test_removing_the_last_owner_is_rejected(): void
    {
        $owner = $this->user(User::ROLE_OWNER);

        $this->expectException(RoleAssignmentException::class);

        try {
            $this->roles->removeUser($owner);
        } finally {
            $this->assertNotNull($owner->fresh(), 'The last Owner must not be removed.');
        }
    }

    public function test_removing_a_non_owner_revokes_access(): void
    {
        $this->user(User::ROLE_OWNER);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->roles->removeUser($admin);

        $this->assertNull($admin->fresh());
    }

    public function test_unknown_role_is_rejected(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->expectException(RoleAssignmentException::class);

        try {
            $this->roles->assignRole($admin, 'superuser');
        } finally {
            $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
        }
    }

    public function test_ownership_transfer_keeps_exactly_one_owner(): void
    {
        $owner = $this->user(User::ROLE_OWNER);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->roles->transferOwnership($owner, $admin);

        $this->assertSame(User::ROLE_ADMIN, $owner->fresh()->role);
        $this->assertSame(User::ROLE_OWNER, $admin->fresh()->role);
        $this->assertSame(1, User::where('company_id', $this->company->id)->where('role', User::ROLE_OWNER)->count());
    }

    public function test_promoting_a_user_to_owner_when_no_owner_exists_is_allowed(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->roles->assignRole($admin, User::ROLE_OWNER);

        $this->assertSame(User::ROLE_OWNER, $admin->fresh()->role);
    }

    public function test_owners_in_different_companies_do_not_conflict(): void
    {
        $this->user(User::ROLE_OWNER);

        $other = Company::factory()->create();
        $otherAdmin = User::factory()->create([
            'company_id' => $other->id,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->roles->assignRole($otherAdmin, User::ROLE_OWNER);

        $this->assertSame(User::ROLE_OWNER, $otherAdmin->fresh()->role);
    }
}
