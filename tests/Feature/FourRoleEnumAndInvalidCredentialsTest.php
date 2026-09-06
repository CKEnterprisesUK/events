<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Task 4.6 — pins two Requirement-3 guarantees explicitly:
 *
 *  (a) THE Platform SHALL support exactly four Company roles: Owner, Admin,
 *      Accountant, and Scanner. (Requirement 3.1)
 *  (b) IF a Company_User submits authentication credentials that do not match a
 *      valid Company_User account, THEN THE Platform SHALL deny access, return
 *      an error indicating the credentials are invalid, and grant no session.
 *      (Requirement 3.9)
 *
 * Broader coverage lives in RoleAuthorizationTest / AuthScaffoldingTest and the
 * Property 6 PBT; this file locks the two named requirements down directly.
 */
class FourRoleEnumAndInvalidCredentialsTest extends TestCase
{
    use RefreshDatabase;

    // --- (a) Exactly four roles are supported. (Requirement 3.1) ---

    public function test_exactly_four_company_roles_are_supported(): void
    {
        $this->assertCount(4, User::ROLES);
        $this->assertSame(
            [
                User::ROLE_OWNER,
                User::ROLE_ADMIN,
                User::ROLE_ACCOUNTANT,
                User::ROLE_SCANNER,
            ],
            User::ROLES,
        );
    }

    public function test_the_four_roles_are_owner_admin_accountant_scanner(): void
    {
        $this->assertSame(['owner', 'admin', 'accountant', 'scanner'], User::ROLES);
    }

    public function test_role_set_contains_no_roles_beyond_the_supported_four(): void
    {
        foreach ([User::ROLE_OWNER, User::ROLE_ADMIN, User::ROLE_ACCOUNTANT, User::ROLE_SCANNER] as $role) {
            $this->assertContains($role, User::ROLES);
        }

        // Nothing outside the closed set is present.
        $this->assertSame(
            [],
            array_diff(User::ROLES, ['owner', 'admin', 'accountant', 'scanner']),
        );
    }

    // --- (b) Invalid credentials are rejected with no session. (Requirement 3.9) ---

    public function test_wrong_password_is_rejected_with_invalid_credentials_error_and_no_session(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => __('auth.failed')]);
    }

    public function test_unknown_email_is_rejected_with_invalid_credentials_error_and_no_session(): void
    {
        // No matching Company_User account exists for this address.
        $response = $this->from('/login')->post('/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever-password',
        ]);

        $this->assertGuest();
        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => __('auth.failed')]);
    }
}
