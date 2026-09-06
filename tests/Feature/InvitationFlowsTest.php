<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use App\Services\RoleAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Task 5.2 — feature tests for the invitation flows, asserting the four
 * requirements at the behavioural level (not just the persisted row):
 *
 *   - 4.2 accepting an invitation creates a Company_User with the assigned
 *         role, scoped to the *inviting* Company, that can authenticate;
 *   - 4.3 an Owner changing a user's role updates that user's *permissions* to
 *         match the newly assigned role (verified against the role gate matrix);
 *   - 4.4 an Owner removing a user *revokes access* — the user can no longer
 *         authenticate and holds no permissions;
 *   - 4.6 the Owner role is not invitable (rejected, no invitation created).
 *
 * These complement the task-5.1 persistence-level checks in
 * {@see InvitationManagementTest} by exercising the permission/access
 * consequences of each flow through the HTTP surface and the authorisation
 * gates the application actually uses.
 *
 * Requirements: 4.2, 4.3, 4.4, 4.6
 */
class InvitationFlowsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an Owner for a fresh Company and return [owner, company].
     *
     * @return array{0: User, 1: Company}
     */
    private function ownerForCompany(): array
    {
        $company = Company::factory()->create();
        $owner = User::factory()->owner()->for($company)->create();

        return [$owner, $company];
    }

    /**
     * Whether the (fresh) user is authorised for the given matrix action via
     * the application's role gates — i.e. their effective permissions.
     */
    private function userCan(User $user, string $action): bool
    {
        return Gate::forUser($user->fresh())->allows($action);
    }

    // ---- 4.2 Accept creates a scoped Company_User ----------------------------

    public function test_accepting_an_invitation_creates_a_scoped_user_with_the_assigned_role(): void
    {
        $company = Company::factory()->create();
        $invitation = Invitation::factory()->for($company)->admin()->create([
            'email' => 'invitee@example.com',
        ]);

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Invited Admin',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $user = User::where('email', 'invitee@example.com')->firstOrFail();

        // Requirement 4.2 — scoped to the *inviting* Company with the assigned role.
        $this->assertTrue($user->belongsToCompany($company));
        $this->assertSame($company->id, $user->company_id);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertFalse($user->isSuperAdmin());

        // The new account can authenticate with the password it just set.
        $this->assertTrue(Auth::validate([
            'email' => 'invitee@example.com',
            'password' => 'secret-password',
        ]));

        // The invitation is consumed (marked accepted) and cannot be reused.
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accepted_user_receives_exactly_the_assigned_roles_permissions(): void
    {
        $company = Company::factory()->create();
        $invitation = Invitation::factory()->for($company)->scanner()->create();

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Invited Scanner',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $user = User::where('email', $invitation->email)->firstOrFail();

        // Requirement 4.2 — the created user's permissions match the assigned
        // (Scanner) role: check-in only, nothing else.
        $this->assertTrue($this->userCan($user, RoleAuthorization::ACTION_CHECK_IN));
        $this->assertFalse($this->userCan($user, RoleAuthorization::ACTION_MANAGE_EVENTS));
        $this->assertFalse($this->userCan($user, RoleAuthorization::ACTION_MANAGE_USERS));
    }

    // ---- 4.3 Role change updates permissions --------------------------------

    public function test_owner_changing_a_role_updates_the_users_permissions(): void
    {
        [$owner, $company] = $this->ownerForCompany();
        $member = User::factory()->scanner()->for($company)->create();

        // Before: a Scanner may check in but may not manage events.
        $this->assertTrue($this->userCan($member, RoleAuthorization::ACTION_CHECK_IN));
        $this->assertFalse($this->userCan($member, RoleAuthorization::ACTION_MANAGE_EVENTS));

        $this->actingAs($owner)->put("/dashboard/users/{$member->id}/role", [
            'role' => User::ROLE_ADMIN,
        ])->assertRedirect(route('dashboard.users.index'));

        // Requirement 4.3 — permissions now match the newly assigned Admin role:
        // event/order management is granted and the Scanner check-in is gone.
        $this->assertSame(User::ROLE_ADMIN, $member->fresh()->role);
        $this->assertTrue($this->userCan($member, RoleAuthorization::ACTION_MANAGE_EVENTS));
        $this->assertTrue($this->userCan($member, RoleAuthorization::ACTION_REFUND_ORDER));
        $this->assertFalse($this->userCan($member, RoleAuthorization::ACTION_CHECK_IN));
        // Admin is not an Owner: user management stays denied.
        $this->assertFalse($this->userCan($member, RoleAuthorization::ACTION_MANAGE_USERS));
    }

    // ---- 4.4 Removal revokes access -----------------------------------------

    public function test_owner_removing_a_user_revokes_all_access(): void
    {
        [$owner, $company] = $this->ownerForCompany();
        $member = User::factory()->admin()->for($company)->create([
            'email' => 'removed@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('secret-password'),
        ]);

        // Sanity: before removal the user can authenticate and holds Admin perms.
        $this->assertTrue(Auth::validate([
            'email' => 'removed@example.com',
            'password' => 'secret-password',
        ]));
        $this->assertTrue($this->userCan($member, RoleAuthorization::ACTION_MANAGE_EVENTS));

        $this->actingAs($owner)->delete("/dashboard/users/{$member->id}")
            ->assertRedirect(route('dashboard.users.index'));

        // Requirement 4.4 — access is revoked: the account no longer exists, so
        // it cannot authenticate.
        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertFalse(Auth::validate([
            'email' => 'removed@example.com',
            'password' => 'secret-password',
        ]));
    }

    // ---- 4.6 Owner is not invitable -----------------------------------------

    public function test_owner_role_cannot_be_invited(): void
    {
        [$owner] = $this->ownerForCompany();

        // Requirement 4.6 — inviting with the Owner role is rejected by
        // validation (role must be one of the invitable roles) and creates no
        // invitation.
        $this->actingAs($owner)->from('/dashboard/users')
            ->post('/dashboard/users/invitations', [
                'email' => 'wannabe-owner@example.com',
                'role' => User::ROLE_OWNER,
            ])->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('invitations', [
            'email' => 'wannabe-owner@example.com',
        ]);
    }

    public function test_only_admin_accountant_and_scanner_are_invitable(): void
    {
        [$owner, $company] = $this->ownerForCompany();

        // Requirement 4.6 — the three non-Owner roles are the invitable set.
        $this->assertSame(
            [User::ROLE_ADMIN, User::ROLE_ACCOUNTANT, User::ROLE_SCANNER],
            User::INVITABLE_ROLES,
        );
        $this->assertNotContains(User::ROLE_OWNER, User::INVITABLE_ROLES);

        foreach (User::INVITABLE_ROLES as $i => $role) {
            $this->actingAs($owner)->post('/dashboard/users/invitations', [
                'email' => "invitee-{$i}@example.com",
                'role' => $role,
            ])->assertRedirect(route('dashboard.users.index'));

            $this->assertDatabaseHas('invitations', [
                'company_id' => $company->id,
                'email' => "invitee-{$i}@example.com",
                'role' => $role,
            ]);
        }
    }
}
