<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RoleAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Pins the role permission matrix (RoleAuthorization) and the registered gates.
 * (Requirements 3.1, 3.3–3.7, 20.7) Universal (role, action) coverage is added
 * by the Property 6 property-based test (task 4.4); this file checks the matrix
 * contents, the four-role set, and Super_Admin bypass.
 */
class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private RoleAuthorization $matrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matrix = app(RoleAuthorization::class);
    }

    public function test_the_supported_company_roles_are_the_closed_set(): void
    {
        $this->assertSame(
            ['owner', 'admin', 'box_office', 'accountant', 'scanner'],
            User::ROLES,
        );
        $this->assertCount(5, User::ROLES);
    }

    public function test_owner_can_do_everything(): void
    {
        // The Owner is the account superuser: they hold the Owner-only actions
        // AND every action delegated to Admin/Accountant/Scanner, so an Owner
        // can manage events, refund orders, view reports and scan tickets too.
        foreach (RoleAuthorization::actions() as $action) {
            $this->assertTrue(
                $this->matrix->roleCan(User::ROLE_OWNER, $action),
                "Owner should be allowed action [{$action}].",
            );
        }
    }

    public function test_admin_permitted_set(): void
    {
        foreach ([
            RoleAuthorization::ACTION_MANAGE_EVENTS,
            RoleAuthorization::ACTION_MANAGE_TICKET_TYPES,
            RoleAuthorization::ACTION_MANAGE_ORDERS,
            RoleAuthorization::ACTION_CANCEL_ORDER,
            RoleAuthorization::ACTION_REFUND_ORDER,
            RoleAuthorization::ACTION_ISSUE_COMP,
            // Admins handle GDPR data-subject requests alongside the Owner.
            RoleAuthorization::ACTION_MANAGE_GDPR,
        ] as $action) {
            $this->assertTrue($this->matrix->roleCan(User::ROLE_ADMIN, $action));
        }

        $this->assertFalse($this->matrix->roleCan(User::ROLE_ADMIN, RoleAuthorization::ACTION_MANAGE_BILLING));
        $this->assertFalse($this->matrix->roleCan(User::ROLE_ADMIN, RoleAuthorization::ACTION_MANAGE_USERS));
        $this->assertFalse($this->matrix->roleCan(User::ROLE_ADMIN, RoleAuthorization::ACTION_MANAGE_SETTINGS));
    }

    public function test_box_office_permitted_set(): void
    {
        // Box_Office runs the box office: events, ticket types and orders
        // (including cancel/refund/comp), the same operational set as Admin.
        foreach ([
            RoleAuthorization::ACTION_MANAGE_EVENTS,
            RoleAuthorization::ACTION_MANAGE_TICKET_TYPES,
            RoleAuthorization::ACTION_MANAGE_ORDERS,
            RoleAuthorization::ACTION_CANCEL_ORDER,
            RoleAuthorization::ACTION_REFUND_ORDER,
            RoleAuthorization::ACTION_ISSUE_COMP,
        ] as $action) {
            $this->assertTrue($this->matrix->roleCan(User::ROLE_BOX_OFFICE, $action));
        }

        // But it is NOT trusted with company settings, users, Stripe, billing,
        // or GDPR handling.
        foreach ([
            RoleAuthorization::ACTION_MANAGE_SETTINGS,
            RoleAuthorization::ACTION_MANAGE_USERS,
            RoleAuthorization::ACTION_MANAGE_STRIPE,
            RoleAuthorization::ACTION_MANAGE_BILLING,
            RoleAuthorization::ACTION_MANAGE_GDPR,
        ] as $action) {
            $this->assertFalse($this->matrix->roleCan(User::ROLE_BOX_OFFICE, $action));
        }
    }

    public function test_only_owner_and_admin_can_handle_gdpr(): void
    {
        $this->assertTrue($this->matrix->roleCan(User::ROLE_OWNER, RoleAuthorization::ACTION_MANAGE_GDPR));
        $this->assertTrue($this->matrix->roleCan(User::ROLE_ADMIN, RoleAuthorization::ACTION_MANAGE_GDPR));

        foreach ([User::ROLE_BOX_OFFICE, User::ROLE_ACCOUNTANT, User::ROLE_SCANNER] as $role) {
            $this->assertFalse($this->matrix->roleCan($role, RoleAuthorization::ACTION_MANAGE_GDPR));
        }
    }

    public function test_accountant_is_read_only_reports(): void
    {
        $this->assertTrue($this->matrix->roleCan(User::ROLE_ACCOUNTANT, RoleAuthorization::ACTION_VIEW_REPORTS));
        $this->assertFalse($this->matrix->roleCan(User::ROLE_ACCOUNTANT, RoleAuthorization::ACTION_MANAGE_EVENTS));
        $this->assertFalse($this->matrix->roleCan(User::ROLE_ACCOUNTANT, RoleAuthorization::ACTION_REFUND_ORDER));
    }

    public function test_scanner_is_check_in_only(): void
    {
        $this->assertTrue($this->matrix->roleCan(User::ROLE_SCANNER, RoleAuthorization::ACTION_CHECK_IN));
        $this->assertFalse($this->matrix->roleCan(User::ROLE_SCANNER, RoleAuthorization::ACTION_VIEW_REPORTS));
        $this->assertFalse($this->matrix->roleCan(User::ROLE_SCANNER, RoleAuthorization::ACTION_MANAGE_EVENTS));
    }

    public function test_gates_authorise_by_role(): void
    {
        $admin = User::factory()->admin()->create();
        $scanner = User::factory()->scanner()->create();

        $this->assertTrue(Gate::forUser($admin)->allows(RoleAuthorization::ACTION_MANAGE_EVENTS));
        $this->assertFalse(Gate::forUser($admin)->allows(RoleAuthorization::ACTION_MANAGE_BILLING));

        $this->assertTrue(Gate::forUser($scanner)->allows(RoleAuthorization::ACTION_CHECK_IN));
        $this->assertFalse(Gate::forUser($scanner)->allows(RoleAuthorization::ACTION_MANAGE_ORDERS));
    }

    public function test_super_admin_bypasses_the_company_matrix(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (RoleAuthorization::actions() as $action) {
            $this->assertTrue(
                Gate::forUser($superAdmin)->allows($action),
                "Super_Admin should be allowed action [{$action}].",
            );
        }

        // But a Super_Admin is not authorised on the raw matrix (no Company role).
        $this->assertFalse($this->matrix->authorize($superAdmin, RoleAuthorization::ACTION_MANAGE_EVENTS));
    }

    public function test_session_is_scoped_to_the_users_own_company(): void
    {
        $admin = User::factory()->admin()->create();
        $otherCompanyAdmin = User::factory()->admin()->create();

        $this->assertTrue($admin->belongsToCompany($admin->company_id));
        $this->assertFalse($admin->belongsToCompany($otherCompanyAdmin->company_id));
    }
}
