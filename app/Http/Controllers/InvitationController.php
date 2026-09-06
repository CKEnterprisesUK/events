<?php

namespace App\Http\Controllers;

use App\Exceptions\RoleAssignmentException;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\RoleAuthorization;
use App\Services\RoleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company-dashboard controller for User Invitation and Management. (Requirement 4)
 *
 * User management is an Owner-only capability: every dashboard write here is
 * gated by the `ACTION_MANAGE_USERS` authorisation Gate, which the role matrix
 * grants to the Owner role (and, via the `Gate::before` bypass, Super_Admins).
 * Anything else is denied with a 403 leaving data unchanged. (Requirement 3.3)
 *
 * Invitations are Company-owned: the invite/role-change/removal actions run
 * under the reserved `/dashboard` prefix where the `dashboard.tenant`
 * middleware binds the authenticated Owner's own Company onto the
 * TenantContext, so the global `company_id` scope constrains every Invitation
 * (and target User) query to the Owner's Company. Accept is a public route (the
 * invited user is not yet authenticated); it resolves the invitation by its
 * opaque token and creates the new Company_User scoped to the *inviting*
 * Company recorded on the invitation. (Requirements 4.1, 4.2, 4.3, 4.4)
 *
 * The assigned role for an invitation is always one of {admin, accountant,
 * scanner}: the Owner role is not invitable. (Requirement 4.6) Role changes and
 * removals are routed through {@see RoleService} so the single-Owner invariant
 * governs any Owner demotion/removal. (Requirement 4.5)
 */
class InvitationController extends Controller
{
    public function __construct(
        private RoleService $roleService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * List the Company's users and pending invitations. (Owner-gated.)
     */
    public function index(RoleAuthorization $authorization): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_USERS);

        $companyId = Auth::user()->company_id;

        return view('dashboard.users.index', [
            'users' => User::query()->where('company_id', $companyId)->get(),
            'invitations' => Invitation::query()->whereNull('accepted_at')->get(),
            'capabilityMatrix' => $authorization->capabilityMatrix(),
        ]);
    }

    /**
     * Invite a user by email to the Owner's Company with an assigned role.
     *
     * Creates an invitation associated with the Owner's Company and the
     * assigned role; the role must be one of {admin, accountant, scanner} —
     * Owner is not invitable. (Requirements 4.1, 4.6)
     */
    public function invite(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_USERS);

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            // Owner is not invitable: only the invitable roles are accepted. (4.6)
            'role' => ['required', 'string', Rule::in(User::INVITABLE_ROLES)],
        ]);

        // company_id is auto-filled from the resolved tenant (the Owner's
        // Company) by the BelongsToCompany trait. (Requirement 4.1)
        $invitation = Invitation::create([
            'email' => $data['email'],
            'role' => $data['role'],
            'token' => Str::random(40),
            'expires_at' => now()->addDays(7),
        ]);

        $this->audit->record(
            action: AuditLog::USER_INVITED,
            auditable: $invitation,
            summary: 'Invited '.$data['email'].' as '.User::roleLabel($data['role']),
            context: ['email' => $data['email'], 'role' => $data['role']],
        );

        return redirect()
            ->route('dashboard.users.index')
            ->with('status', 'Invitation sent.');
    }

    /**
     * Show the accept form for a pending invitation (public, by token).
     */
    public function showAccept(string $token): View
    {
        $invitation = $this->pendingInvitationOrFail($token);

        return view('invitations.accept', ['invitation' => $invitation]);
    }

    /**
     * Accept an invitation, creating a Company_User with the assigned role
     * scoped to the inviting Company. (Requirement 4.2)
     *
     * Public route: the invited user is not authenticated yet. The invitation
     * is resolved by its opaque token (pending + unexpired), and the new user's
     * `company_id` is taken from the invitation so the account is scoped to the
     * inviting Company; the invitation is then marked accepted.
     */
    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->pendingInvitationOrFail($token);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Create the Company_User scoped to the *inviting* Company with the
        // invitation's assigned role (always a non-Owner invitable role). (4.2)
        $user = User::create([
            'company_id' => $invitation->company_id,
            'role' => $invitation->role,
            'name' => $data['name'],
            'email' => $invitation->email,
            'password' => Hash::make($data['password']),
            'last_activity_at' => now(),
        ]);

        $invitation->forceFill(['accepted_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard')
            ->with('status', 'Welcome — your account is ready.');
    }

    /**
     * Change a Company_User's role, updating their permissions to match.
     * (Requirement 4.3)
     *
     * Routed through {@see RoleService}: demoting the Company's last Owner is
     * rejected by the single-Owner invariant, leaving the Owner unchanged.
     * (Requirement 4.5)
     */
    public function updateRole(Request $request, User $user): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_USERS);

        $this->assertSameCompany($user);

        $data = $request->validate([
            'role' => ['required', 'string', Rule::in(User::ROLES)],
        ]);

        $previousRole = $user->role;

        try {
            $this->roleService->assignRole($user, $data['role']);
        } catch (RoleAssignmentException $e) {
            throw ValidationException::withMessages(['role' => $e->getMessage()]);
        }

        $this->audit->record(
            action: AuditLog::USER_ROLE_CHANGED,
            auditable: $user,
            summary: 'Changed '.$user->email.' from '
                .User::roleLabel($previousRole).' to '.User::roleLabel($data['role']),
            context: [
                'user_id' => (int) $user->getKey(),
                'from' => $previousRole,
                'to' => $data['role'],
            ],
        );

        return redirect()
            ->route('dashboard.users.index')
            ->with('status', 'Role updated.');
    }

    /**
     * Remove a Company_User, revoking their access to the Company.
     * (Requirement 4.4)
     *
     * Routed through {@see RoleService}: removing the Company's last Owner is
     * rejected by the single-Owner invariant, leaving the Owner unchanged.
     * (Requirement 4.5)
     */
    public function destroy(User $user): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_USERS);

        $this->assertSameCompany($user);

        // Snapshot identity before removal so the trail names the removed user
        // even though the row is gone (and the auditable id would dangle).
        $removedEmail = $user->email;
        $removedRole = $user->role;
        $removedCompanyId = (int) $user->company_id;

        try {
            $this->roleService->removeUser($user);
        } catch (RoleAssignmentException $e) {
            throw ValidationException::withMessages(['user' => $e->getMessage()]);
        }

        $this->audit->record(
            action: AuditLog::USER_REMOVED,
            summary: 'Removed '.$removedEmail.' ('.User::roleLabel($removedRole).')',
            context: ['email' => $removedEmail, 'role' => $removedRole],
            companyId: $removedCompanyId,
        );

        return redirect()
            ->route('dashboard.users.index')
            ->with('status', 'User removed.');
    }

    /**
     * Resolve a pending, unexpired invitation by token or 404. The User model
     * is not tenant-scoped and the accept route establishes no Company, so the
     * lookup is by the globally-unique token; the invitation itself carries the
     * `company_id` the new user is scoped to.
     */
    private function pendingInvitationOrFail(string $token): Invitation
    {
        $invitation = Invitation::withoutGlobalScopes()
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->first();

        abort_if($invitation === null, 404);

        return $invitation;
    }

    /**
     * Reject managing a user that belongs to a different Company. The target is
     * resolved outside the tenant scope (the User model is not scoped), so this
     * guards cross-Company role changes/removals with a 404. (Requirements 3.10)
     */
    private function assertSameCompany(User $user): void
    {
        $companyId = Auth::user()->company_id;

        abort_if($companyId === null || $user->company_id !== $companyId, 404);
    }
}
