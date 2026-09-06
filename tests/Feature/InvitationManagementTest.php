<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 5.1 — the invitations table/model and InvitationController flows:
 * invite-by-email, accept (creates a scoped Company_User), role change, and
 * removal, with the assigned role constrained to {admin, accountant, scanner}
 * and Owner removal/demotion routed through the single-Owner invariant.
 *
 * Requirements: 4.1 (invite creates invitation for Owner's Company + role),
 * 4.2 (accept creates scoped Company_User), 4.3 (role change updates
 * permissions), 4.4 (removal revokes access), 4.5 (last-Owner protection),
 * 4.6 (Owner not invitable).
 */
class InvitationManagementTest extends TestCase
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

    // ---- Invite by email (4.1, 4.6) -----------------------------------------

    public function test_owner_invites_a_user_creating_an_invitation_for_their_company(): void
    {
        [$owner, $company] = $this->ownerForCompany();

        $this->actingAs($owner)->post('/dashboard/users/invitations', [
            'email' => 'invitee@example.com',
            'role' => User::ROLE_ADMIN,
        ])->assertRedirect(route('dashboard.users.index'));

        // Requirement 4.1 — invitation associated with the Owner's Company + role.
        $this->assertDatabaseHas('invitations', [
            'company_id' => $company->id,
            'email' => 'invitee@example.com',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_owner_cannot_invite_a_user_as_owner(): void
    {
        [$owner] = $this->ownerForCompany();

        // Requirement 4.6 — the assigned role must be one of the invitable
        // roles; Owner is rejected by validation and no invitation is created.
        $this->actingAs($owner)->from('/dashboard/users')
            ->post('/dashboard/users/invitations', [
                'email' => 'invitee@example.com',
                'role' => User::ROLE_OWNER,
            ])->assertSessionHasErrors('role');

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_each_invitable_role_is_accepted(): void
    {
        [$owner] = $this->ownerForCompany();

        foreach (User::INVITABLE_ROLES as $i => $role) {
            $this->actingAs($owner)->post('/dashboard/users/invitations', [
                'email' => "invitee{$i}@example.com",
                'role' => $role,
            ])->assertRedirect();

            $this->assertDatabaseHas('invitations', [
                'email' => "invitee{$i}@example.com",
                'role' => $role,
            ]);
        }
    }

    public function test_non_owner_roles_cannot_manage_users(): void
    {
        // Requirement 3.3 — user management is Owner-only.
        foreach ([
            User::factory()->admin()->create(),
            User::factory()->accountant()->create(),
            User::factory()->scanner()->create(),
        ] as $user) {
            $this->actingAs($user)->post('/dashboard/users/invitations', [
                'email' => 'nope@example.com',
                'role' => User::ROLE_ADMIN,
            ])->assertForbidden();
        }

        $this->assertDatabaseCount('invitations', 0);
    }

    // ---- Accept (4.2) --------------------------------------------------------

    public function test_accepting_an_invitation_creates_a_user_scoped_to_the_inviting_company(): void
    {
        $company = Company::factory()->create();
        $invitation = Invitation::factory()->for($company)->accountant()->create([
            'email' => 'newuser@example.com',
        ]);

        $response = $this->post("/invitations/{$invitation->token}", [
            'name' => 'New User',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertRedirect();

        // Requirement 4.2 — a Company_User is created with the assigned role,
        // scoped to the inviting Company.
        $this->assertDatabaseHas('users', [
            'company_id' => $company->id,
            'email' => 'newuser@example.com',
            'role' => User::ROLE_ACCOUNTANT,
        ]);

        $user = User::where('email', 'newuser@example.com')->first();
        $this->assertTrue($user->belongsToCompany($company));

        // The invitation is marked accepted.
        $this->assertNotNull($invitation->fresh()->accepted_at);

        // The new user is logged in.
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_an_already_accepted_invitation_cannot_be_reused(): void
    {
        $invitation = Invitation::factory()->accepted()->create();

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Someone',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertNotFound();
    }

    public function test_an_expired_invitation_cannot_be_accepted(): void
    {
        $invitation = Invitation::factory()->expired()->create();

        $this->post("/invitations/{$invitation->token}", [
            'name' => 'Someone',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertNotFound();
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get('/invitations/does-not-exist')->assertNotFound();
    }

    // ---- Role change (4.3, 4.5) ---------------------------------------------

    public function test_owner_changes_a_users_role(): void
    {
        [$owner, $company] = $this->ownerForCompany();
        $member = User::factory()->scanner()->for($company)->create();

        // Requirement 4.3 — the user's role/permissions are updated.
        $this->actingAs($owner)->put("/dashboard/users/{$member->id}/role", [
            'role' => User::ROLE_ADMIN,
        ])->assertRedirect(route('dashboard.users.index'));

        $this->assertSame(User::ROLE_ADMIN, $member->fresh()->role);
    }

    public function test_demoting_the_last_owner_is_rejected_by_the_single_owner_invariant(): void
    {
        [$owner, $company] = $this->ownerForCompany();

        // Requirement 4.5 — demoting the last Owner is rejected; Owner unchanged.
        $this->actingAs($owner)->from('/dashboard/users')
            ->put("/dashboard/users/{$owner->id}/role", [
                'role' => User::ROLE_ADMIN,
            ])->assertSessionHasErrors('role');

        $this->assertSame(User::ROLE_OWNER, $owner->fresh()->role);
    }

    public function test_owner_cannot_change_the_role_of_another_companys_user(): void
    {
        [$owner] = $this->ownerForCompany();
        $foreign = User::factory()->scanner()->create(); // different Company

        // Requirement 3.10 — cross-Company management is denied (404).
        $this->actingAs($owner)->put("/dashboard/users/{$foreign->id}/role", [
            'role' => User::ROLE_ADMIN,
        ])->assertNotFound();

        $this->assertSame(User::ROLE_SCANNER, $foreign->fresh()->role);
    }

    // ---- Removal (4.4, 4.5) --------------------------------------------------

    public function test_owner_removes_a_user_revoking_access(): void
    {
        [$owner, $company] = $this->ownerForCompany();
        $member = User::factory()->admin()->for($company)->create();

        // Requirement 4.4 — removal revokes the user's access.
        $this->actingAs($owner)->delete("/dashboard/users/{$member->id}")
            ->assertRedirect(route('dashboard.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
    }

    public function test_removing_the_last_owner_is_rejected_by_the_single_owner_invariant(): void
    {
        [$owner] = $this->ownerForCompany();

        // Requirement 4.5 — removing the last Owner is rejected; Owner unchanged.
        $this->actingAs($owner)->from('/dashboard/users')
            ->delete("/dashboard/users/{$owner->id}")
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
            'role' => User::ROLE_OWNER,
        ]);
    }

    public function test_owner_cannot_remove_another_companys_user(): void
    {
        [$owner] = $this->ownerForCompany();
        $foreign = User::factory()->admin()->create(); // different Company

        // Requirement 3.10 — cross-Company removal is denied (404).
        $this->actingAs($owner)->delete("/dashboard/users/{$foreign->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('users', ['id' => $foreign->id]);
    }

    // ---- Invitations are Company-owned / tenant-scoped ----------------------

    public function test_invitation_index_lists_only_the_owners_company_invitations(): void
    {
        [$owner, $company] = $this->ownerForCompany();
        Invitation::factory()->for($company)->create(['email' => 'mine@example.com']);
        Invitation::factory()->create(['email' => 'theirs@example.com']); // other Company

        $response = $this->actingAs($owner)->get('/dashboard/users');

        $response->assertStatus(200);
        $response->assertSee('mine@example.com');
        $response->assertDontSee('theirs@example.com');
    }
}
