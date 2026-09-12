<?php

namespace App\Services;

use App\Models\User;

/**
 * The role permission matrix (design Property 6): maps each of the five Company
 * roles to the closed set of abstract actions it may perform, and answers
 * whether a given (role, action) pair is authorised.
 *
 * Permitted sets (Requirements 3.3–3.7):
 *   - Owner:       every Company action (the Owner is the account superuser and
 *                  can do anything a lower role can, in addition to the
 *                  Owner-only billing/Stripe-management/settings/user-management)
 *   - Admin:       manage Events, Ticket_Types, Orders (incl. cancel/refund/comp),
 *                  GDPR data-subject handling, and Stripe Connect *setup* (getting
 *                  the account connected) — but NOT ongoing Stripe management
 *                  (fee handling), which stays Owner-only
 *   - Box_Office:  manage Events, Ticket_Types, Orders (incl. cancel/refund/comp)
 *                  — a cut-down Admin with no Company settings or GDPR access
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

    // Managing an already-connected Stripe account: changing how the platform
    // fee is handled (Absorb vs Pass_On) and other post-setup billing levers.
    // Owner-only — the Admin can get the account connected but not change how
    // money is handled once it is.
    public const ACTION_MANAGE_STRIPE = 'stripe';

    // Setting up / onboarding the Company's Stripe Connect account: viewing the
    // connection status, starting Connect onboarding, and handling the return.
    // Held by the Owner AND the Admin so an Admin can get payments connected,
    // while ongoing management (fees) stays Owner-only via ACTION_MANAGE_STRIPE.
    public const ACTION_SETUP_STRIPE = 'stripe_setup';

    public const ACTION_MANAGE_SETTINGS = 'settings';

    public const ACTION_MANAGE_USERS = 'users';

    // Admin actions (event/ticket/order management including money operations).
    public const ACTION_MANAGE_EVENTS = 'events';

    public const ACTION_MANAGE_TICKET_TYPES = 'ticket_types';

    public const ACTION_MANAGE_ORDERS = 'orders';

    public const ACTION_CANCEL_ORDER = 'cancel_order';

    public const ACTION_REFUND_ORDER = 'refund_order';

    public const ACTION_ISSUE_COMP = 'issue_comp';

    // GDPR data-subject handling (export / anonymise). Held by the Owner and
    // Admin: it is a data-controller compliance responsibility, so it sits with
    // the roles trusted with the Company's Customer records — but not with the
    // Box_Office/Accountant/Scanner operational roles.
    public const ACTION_MANAGE_GDPR = 'gdpr';

    // View the Company's audit log / activity trail. Held by the Owner and
    // Admin: it can reveal sensitive operational patterns (money, access,
    // privacy actions), so it stays with the roles trusted with oversight and
    // not the Box_Office/Accountant/Scanner operational roles.
    public const ACTION_VIEW_AUDIT_LOG = 'view_audit_log';

    // Accountant action (read-only reporting/payouts).
    public const ACTION_VIEW_REPORTS = 'view_reports';

    // Scanner action (check-in only).
    public const ACTION_CHECK_IN = 'check_in';

    // Reset (clear) all check-ins for an event so the door can re-scan from a
    // clean slate. Deliberately narrower than ACTION_MANAGE_EVENTS: it wipes
    // operational check-in state for a whole event, so it is held by the Owner
    // and Admin only — NOT the Box_Office role that otherwise manages events.
    public const ACTION_RESET_SCANS = 'reset_scans';

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
            self::ACTION_MANAGE_GDPR,
            self::ACTION_VIEW_AUDIT_LOG,
            self::ACTION_RESET_SCANS,
            // The Admin can connect the Company's Stripe account (onboarding),
            // but NOT manage it afterwards (fee handling) — that stays Owner-only
            // via ACTION_MANAGE_STRIPE.
            self::ACTION_SETUP_STRIPE,
        ],
        // Box_Office is a cut-down Admin: it runs the box office (events,
        // ticket types, orders incl. cancel/refund/comp) but is NOT trusted
        // with Company settings, users, Stripe, billing, or GDPR handling.
        User::ROLE_BOX_OFFICE => [
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

    /**
     * A display-ready capability matrix for the team page. Each capability is a
     * human-friendly label paired with the representative action that gates it;
     * `roles` maps every Company role to whether it may perform that action, so
     * the rendered table always mirrors the real permission matrix above.
     *
     * @return list<array{label: string, roles: array<string, bool>}>
     */
    public function capabilityMatrix(): array
    {
        $capabilities = [
            'Manage events' => self::ACTION_MANAGE_EVENTS,
            'Manage ticket types' => self::ACTION_MANAGE_TICKET_TYPES,
            'Manage orders' => self::ACTION_MANAGE_ORDERS,
            'Cancel / refund orders' => self::ACTION_REFUND_ORDER,
            'Issue comp tickets' => self::ACTION_ISSUE_COMP,
            'View reports & payouts' => self::ACTION_VIEW_REPORTS,
            'Check in attendees' => self::ACTION_CHECK_IN,
            'Reset event check-ins' => self::ACTION_RESET_SCANS,
            'Handle GDPR requests' => self::ACTION_MANAGE_GDPR,
            'View the activity log' => self::ACTION_VIEW_AUDIT_LOG,
            'Manage company settings' => self::ACTION_MANAGE_SETTINGS,
            'Connect Stripe (payments setup)' => self::ACTION_SETUP_STRIPE,
            'Manage Stripe fees & payouts' => self::ACTION_MANAGE_STRIPE,
            'Manage billing' => self::ACTION_MANAGE_BILLING,
            'Manage the team' => self::ACTION_MANAGE_USERS,
        ];

        $rows = [];

        foreach ($capabilities as $label => $action) {
            $roles = [];

            foreach (User::ROLES as $role) {
                $roles[$role] = $this->roleCan($role, $action);
            }

            $rows[] = ['label' => $label, 'roles' => $roles];
        }

        return $rows;
    }
}
