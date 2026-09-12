<?php

namespace Tests\PBT;

use App\Models\User;
use App\Services\RoleAuthorization;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/**
 * Property-based test for the role permission matrix (design Property 6).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Generates random
 * (role, action) pairs spanning all Company roles and every action the
 * matrix knows about, and asserts authorisation is granted iff the action is
 * in that role's permitted set — checked at the matrix (`roleCan`), the user
 * (`authorize`), and the registered Gate. Denied actions must leave data
 * unchanged.
 *
 * **Validates: Requirements 3.3, 3.4, 3.5, 3.6, 3.7, 20.7, 21.1, 21.2**
 */
class RolePermissionMatrixTest extends PbtTestCase
{
    use RefreshDatabase;

    private RoleAuthorization $matrix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matrix = app(RoleAuthorization::class);
    }

    /**
     * The independently-computed oracle: the closed permitted set for each
     * role, expressed directly from the design's Property 6 statement rather
     * than by reading it back from the implementation under test.
     *
     * @return array<string, list<string>>
     */
    private function expectedMatrix(): array
    {
        return [
            // The Owner is the account superuser: their permitted set is the
            // union of every action the matrix knows about (Owner-only actions
            // plus everything delegated to Admin/Accountant/Scanner).
            User::ROLE_OWNER => RoleAuthorization::actions(),
            User::ROLE_ADMIN => [
                RoleAuthorization::ACTION_MANAGE_EVENTS,
                RoleAuthorization::ACTION_MANAGE_TICKET_TYPES,
                RoleAuthorization::ACTION_MANAGE_ORDERS,
                RoleAuthorization::ACTION_CANCEL_ORDER,
                RoleAuthorization::ACTION_REFUND_ORDER,
                RoleAuthorization::ACTION_ISSUE_COMP,
                RoleAuthorization::ACTION_MANAGE_GDPR,
                RoleAuthorization::ACTION_VIEW_AUDIT_LOG,
                RoleAuthorization::ACTION_RESET_SCANS,
                // The Admin can set up (connect) Stripe, but managing an already
                // connected account (fee handling) stays Owner-only.
                RoleAuthorization::ACTION_SETUP_STRIPE,
            ],
            // Box_Office is a cut-down Admin: the operational set with no
            // company-settings/GDPR access.
            User::ROLE_BOX_OFFICE => [
                RoleAuthorization::ACTION_MANAGE_EVENTS,
                RoleAuthorization::ACTION_MANAGE_TICKET_TYPES,
                RoleAuthorization::ACTION_MANAGE_ORDERS,
                RoleAuthorization::ACTION_CANCEL_ORDER,
                RoleAuthorization::ACTION_REFUND_ORDER,
                RoleAuthorization::ACTION_ISSUE_COMP,
            ],
            User::ROLE_ACCOUNTANT => [
                RoleAuthorization::ACTION_VIEW_REPORTS,
            ],
            User::ROLE_SCANNER => [
                RoleAuthorization::ACTION_CHECK_IN,
            ],
        ];
    }

    /**
     * Whether the oracle says the given role may perform the given action.
     */
    private function expectedAuthorised(string $role, string $action): bool
    {
        return in_array($action, $this->expectedMatrix()[$role] ?? [], true);
    }

    /**
     * Property 6: Role permission matrix — authorise (role, action) iff action
     * is in that role's permitted set; denied actions leave data unchanged.
     *
     * **Validates: Requirements 3.3, 3.4, 3.5, 3.6, 3.7, 20.7, 21.1, 21.2**
     */
    // Feature: event-ticketing-platform, Property 6: Role permission matrix — authorise (role, action) iff action is in that role's permitted set; denied actions leave data unchanged
    public function test_authorised_iff_action_in_role_permitted_set(): void
    {
        // One persisted user per role, reused across iterations so the property
        // exercises both the pure matrix and the Gate/policy surface without
        // creating a row on every draw.
        $users = [];
        foreach (User::ROLES as $role) {
            $users[$role] = User::factory()->create(['role' => $role]);
        }

        $allActions = RoleAuthorization::actions();

        // The generated action space spans every action the matrix knows about
        // plus an action outside the matrix entirely, so the "denied" branch is
        // exercised for actions no role permits.
        $actionSpace = array_merge($allActions, ['unknown_action']);

        $this->forAll(
            Generator\elements(...User::ROLES),
            Generator\elements(...$actionSpace),
        )
            ->then(function (string $role, string $action) use ($users): void {
                $expected = $this->expectedAuthorised($role, $action);
                $user = $users[$role];

                // The matrix is the single source of truth.
                $this->assertSame(
                    $expected,
                    $this->matrix->roleCan($role, $action),
                    sprintf('roleCan(%s, %s)', $role, $action),
                );

                // A user with that role must be authorised exactly the same.
                $this->assertSame(
                    $expected,
                    $this->matrix->authorize($user, $action),
                    sprintf('authorize(user[%s], %s)', $role, $action),
                );

                // The registered Gate must agree for known actions. Unknown
                // actions have no gate registered, so Gate would abstain; we
                // only assert the Gate for actions the matrix defines.
                if (in_array($action, RoleAuthorization::actions(), true)) {
                    $this->assertSame(
                        $expected,
                        Gate::forUser($user)->allows($action),
                        sprintf('Gate[%s]->allows(%s)', $role, $action),
                    );
                }

                // Denied actions leave data unchanged: a denial is a pure
                // read-only decision that touches no state. The persisted user
                // row must be identical before and after the denied check.
                if (! $expected) {
                    $before = User::query()->whereKey($user->getKey())->first()->getAttributes();
                    $this->matrix->authorize($user, $action);
                    Gate::forUser($user)->allows($action);
                    $after = User::query()->whereKey($user->getKey())->first()->getAttributes();
                    $this->assertSame(
                        $before,
                        $after,
                        'A denied authorisation check must not mutate the user.',
                    );
                }
            });
    }
}
