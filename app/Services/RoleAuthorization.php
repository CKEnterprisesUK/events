<?php

namespace App\Services;

use App\Models\User;

/**
 * The role permission matrix (design Property 6): maps each of the four Company
 * roles to the closed set of abstract actions it may perform, and answers
 * whether a given (role, action) pair is authorised.
 *
 * Permitted sets (Requirements 3.3–3.7):
 *   - Owner:       every Company action (the Owner is the account superuser and
 *                  can do anything an Admin/Accountant/Scanner can, in addition
 *                  to the Owner-only billing/Stripe/settings/user-management)
 *   - Admin:       manage Events, Ticket_Types, Orders (incl. cancel/refund/comp)
 *   - Accountant:  read-only reports/payouts
 *   - Scanner:     check-in only
 *
 * A user is authorised for an action iff the action belongs to their role's
 * permitted set. The Owner's permitted set is the union of every action the
 * matrix knows about, so the Owner stays all-powerful automatically as new
 * actions are added. Anything else is denied (the policies/gates translate a
 * denial into an authorisation error, leaving data unchanged). Super_Admins are
 * gated on the separate super-admin surface and are not part of this Company
 * matrix.
 */
class RoleAuthorization
{
    // Owner-only actions.
    public const ACTION_MANAGE_BILLING = 'billing';

    public const ACTION_MANAGE_STRIPE = 'stripe';

    public const ACTION_MANAGE_SETTINGS = 'settings';

    public const ACTION_MANAGE_USERS = 'users';

    // Admin actions (event/ticket/order management including money operations).
    public const ACTION_MANAGE_EVENTS = 'events';

    public const ACTION_MANAGE_TICKET_TYPES = 'ticket_types';

    public const ACTION_MANAGE_ORDERS = 'orders';

    public const ACTION_CANCEL_ORDER = 'cancel_order';

    public const ACTION_REFUND_ORDER = 'refund_order';

    public const ACTION_ISSUE_COMP = 'issue_comp';

    // Accountant action (read-only reporting/payouts).
    public const ACTION_VIEW_REPORTS = 'view_reports';

    // Scanner action (check-in only).
    public const ACTION_CHECK_IN = 'check_in';

    /**
     * The base role → permitted-actions matrix. The values here are the closed
     * set of actions each role may perform. The Owner is a special case: rather
     * than being listed here, the Owner is granted the union of every action
     * (see {@see ownerActions()}), so the Owner is always able to do everything
     * an Admin/Accountant/Scanner can, plus the Owner-only actions below.
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        User::ROLE_OWNER => [
            self::ACTION_MANAGE_BILLING,
            self::ACTION_MANAGE_STRIPE,
            self::ACTION_MANAGE_SETTINGS,
            self::ACTION_MANAGE_USERS,
        ],
        User::ROLE_ADMIN => [
            self::ACTION_MANAGE_EVENTS,
            self::ACTION_MANAGE_TICKET_TYPES,
            self::ACTION_MANAGE_ORDERS,
            self::ACTION_CANCEL_ORDER,
            self::ACTION_REFUND_ORDER,
            self::ACTION_ISSUE_COMP,
        ],
        User::ROLE_ACCOUNTANT => [
            self::ACTION_VIEW_REPORTS,
        ],
        User::ROLE_SCANNER => [
            self::ACTION_CHECK_IN,
        ],
    ];

    /**
     * Every action the matrix knows about (the union of all permitted sets).
     * This is also exactly the Owner's permitted set. (Property 6)
     *
     * @return list<string>
     */
    public static function actions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::MATRIX))));
    }

    /**
     * The permitted action set for a role, or an empty list for an unknown
     * role. The Owner is granted every action the matrix knows about, so the
     * Owner is a full account superuser within their Company.
     *
     * @return list<string>
     */
    public function permittedActions(string $role): array
    {
        if ($role === User::ROLE_OWNER) {
            return self::actions();
        }

        return self::MATRIX[$role] ?? [];
    }

    /**
     * Whether the given role is permitted to perform the given action. The
     * single source of truth for the permission matrix. (Property 6)
     */
    public function roleCan(string $role, string $action): bool
    {
        return in_array($action, $this->permittedActions($role), true);
    }

    /**
     * Whether the given user is authorised for the action, based solely on
     * their Company role. Users with no Company role (e.g. Super_Admins) are
     * not authorised on the Company matrix.
     */
    public function authorize(User $user, string $action): bool
    {
        if ($user->role === null) {
            return false;
        }

        return $this->roleCan($user->role, $action);
    }
}
