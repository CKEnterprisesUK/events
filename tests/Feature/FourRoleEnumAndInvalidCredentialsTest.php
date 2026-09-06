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
 *  (a) THE Platform SHALL support a closed set of Company roles: Owner, Admin,
 *      Box_Office, Accountant, and Scanner. (Requirement 3.1)
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

    // --- (a) The closed role set is supported. (Requirement 3.1) ---

    /**
     * The complete, ordered closed set of Company roles.
     *
     * @var list<string>
     */
    private const EXPECTED_ROLES = ['owner', 'admin', 'box_office', 'accountant', 'scanner'];

    public function test_the_closed_company_role_set_is_supported(): void
    {
        $this->assertCount(count(self::EXPECTED_ROLES), User::ROLES);
        $this->assertSame(
            [
                User::ROLE_OWNER,
                User::ROLE_ADMIN,
                User::ROLE_BOX_OFFICE,
                User::ROLE_ACCOUNTANT,
                User::ROLE_SCANNER,
            ],
            User::ROLES,
        );
    }

    public function test_the_roles_are_owner_admin_box_office_accountant_scanner(): void
    {
        $this->assertSame(self::EXPECTED_ROLES, User::ROLES);
    }

    public function test_role_set_contains_no_roles_beyond_the_supported_set(): void
    {
        foreach (self::EXPECTED_ROLES as $role) {
            $this->assertContains($role, User::ROLES);
        }

        // Nothing outside the closed set is present.
        $this->assertSame(
            [],
            array_diff(User::ROLES, self::EXPECTED_ROLES),
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
