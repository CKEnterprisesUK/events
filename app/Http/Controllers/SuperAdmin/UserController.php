<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Super-admin cross-tenant user management: a platform-wide directory of every
 * Company_User, plus the per-user actions a Super_Admin needs to help a client
 * whose team member is locked out or needs changing — without impersonating the
 * tenant or touching the database by hand:
 *
 *   - update:            edit a user's name, email and Company role.
 *   - disable / enable:  reversibly block a user from logging in (keeps the row
 *                        and its audit history); the block takes effect on the
 *                        user's next request via {@see \App\Http\Middleware\EnsureCompanyActive}.
 *   - destroy:           hard-delete a user account.
 *   - sendPasswordReset: email the user a password-reset link.
 *   - resendVerification: re-send the email-verification link.
 *
 * NOT tenant-scoped — a Super_Admin operates across every Company. This surface
 * deals ONLY with Company_Users: Super_Admin accounts (`is_super_admin = true`)
 * are provisioned out-of-band and are never listed or mutated here, so every
 * lookup excludes them and any attempt to resolve one 404s. The `{user}` route
 * segment is a plain id resolved explicitly (rather than implicit binding) so
 * this exclusion is enforced in one place.
 *
 * Role edits can never mint a second Owner: the single-Owner invariant is owned
 * by {@see CompanyUserController::transferOwnership} (and a DB unique index), so
 * the role field here is restricted to the non-Owner roles, and the current
 * Owner's role cannot be changed from this form (transfer ownership instead).
 * Every action is recorded on the audit trail against the user's Company.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Platform-wide user directory: every Company_User, searchable by name or
     * email and filterable by Company, role and status (active / disabled).
     * Super_Admins are excluded. Each row carries its Company for display.
     */
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));

        $companyId = (int) $request->query('company', 0);
        $companyId = $companyId > 0 ? $companyId : null;

        $role = (string) $request->query('role', '');
        $role = in_array($role, User::ROLES, true) ? $role : '';

        $status = (string) $request->query('status', '');
        $status = in_array($status, ['active', 'disabled'], true) ? $status : '';

        $users = User::query()
            ->where('is_super_admin', false)
            ->with('company')
            ->when($search !== '', function ($query) use ($search): void {
                $like = '%'.$search.'%';
                $query->where(function ($q) use ($like): void {
                    $q->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like);
                });
            })
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($role !== '', fn ($query) => $query->where('role', $role))
            ->when($status === 'active', fn ($query) => $query->whereNull('disabled_at'))
            ->when($status === 'disabled', fn ($query) => $query->whereNotNull('disabled_at'))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        // Company dropdown for the filter, alphabetical.
        $companies = Company::query()->orderBy('name')->get(['id', 'name']);

        return view('admin.users.index', [
            'users' => $users,
            'companies' => $companies,
            'search' => $search,
            'companyId' => $companyId,
            'role' => $role,
            'status' => $status,
        ]);
    }

    /**
     * Edit a user's name, email and role. The email must stay unique across the
     * Platform. The role is restricted to the non-Owner roles: promotion to
     * Owner is only ever done via {@see CompanyUserController::transferOwnership}
     * (single-Owner invariant), and the current Owner's role cannot be changed
     * here — the form omits the role control for an Owner.
     */
    public function update(Request $request, int $user): RedirectResponse
    {
        $target = $this->resolveUser($user);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:254',
                Rule::unique('users', 'email')->ignore($target->getKey()),
            ],
        ];

        // The Owner's role is fixed here (change it via ownership transfer). For
        // everyone else, the role may be set to any NON-Owner role.
        if (! $target->isOwner()) {
            $rules['role'] = ['required', Rule::in(User::INVITABLE_ROLES)];
        }

        $validated = $request->validate($rules);

        $changes = [];

        if ($target->name !== $validated['name']) {
            $changes['name'] = ['from' => $target->name, 'to' => $validated['name']];
            $target->name = $validated['name'];
        }

        if ($target->email !== $validated['email']) {
            $changes['email'] = ['from' => $target->email, 'to' => $validated['email']];
            $target->email = $validated['email'];
        }

        if (! $target->isOwner() && $target->role !== $validated['role']) {
            $changes['role'] = ['from' => $target->role, 'to' => $validated['role']];
            $target->role = $validated['role'];
        }

        if ($changes === []) {
            return $this->back($target)->with('status', __('No changes to save.'));
        }

        $target->save();

        $this->audit->record(
            action: AuditLog::USER_UPDATED,
            auditable: $target,
            summary: 'Updated the account for '.$target->email,
            context: ['user_id' => $target->getKey(), 'changes' => array_keys($changes)],
            companyId: $target->company_id !== null ? (int) $target->company_id : null,
        );

        return $this->back($target)->with('status', __('Account updated for :email.', [
            'email' => $target->email,
        ]));
    }

    /**
     * Reversibly disable a user: stamps `disabled_at` so the user can no longer
     * log in and any live session is torn down on their next request. Kept
     * distinct from delete so the account (and its history) survives and the
     * block can be lifted. A no-op if already disabled.
     */
    public function disable(int $user): RedirectResponse
    {
        $target = $this->resolveUser($user);

        if ($target->isDisabled()) {
            return $this->back($target)->with('status', __('That account is already disabled.'));
        }

        // Written via forceFill: disabled_at is deliberately out of $fillable.
        $target->forceFill(['disabled_at' => now()])->save();

        $this->audit->record(
            action: AuditLog::USER_DISABLED,
            auditable: $target,
            summary: 'Disabled the account for '.$target->email,
            context: ['user_id' => $target->getKey()],
            companyId: $target->company_id !== null ? (int) $target->company_id : null,
        );

        return $this->back($target)->with('status', __('Disabled the account for :email.', [
            'email' => $target->email,
        ]));
    }

    /**
     * Re-enable a previously disabled user by clearing `disabled_at`. A no-op if
     * the account is already active.
     */
    public function enable(int $user): RedirectResponse
    {
        $target = $this->resolveUser($user);

        if (! $target->isDisabled()) {
            return $this->back($target)->with('status', __('That account is already active.'));
        }

        $target->forceFill(['disabled_at' => null])->save();

        $this->audit->record(
            action: AuditLog::USER_ENABLED,
            auditable: $target,
            summary: 'Re-enabled the account for '.$target->email,
            context: ['user_id' => $target->getKey()],
            companyId: $target->company_id !== null ? (int) $target->company_id : null,
        );

        return $this->back($target)->with('status', __('Re-enabled the account for :email.', [
            'email' => $target->email,
        ]));
    }

    /**
     * Hard-delete a user account. Records the deletion against the user's
     * Company BEFORE the row is removed (the audit row snapshots the actor and a
     * human summary, so it still reads sensibly with the user gone).
     */
    public function destroy(int $user): RedirectResponse
    {
        $target = $this->resolveUser($user);

        $email = $target->email;
        $companyId = $target->company_id !== null ? (int) $target->company_id : null;

        // Record first so the audit trail retains the fact of deletion even
        // though the auditable row is about to disappear.
        $this->audit->record(
            action: AuditLog::USER_DELETED,
            auditable: $target,
            summary: 'Deleted the account for '.$email,
            context: ['user_id' => $target->getKey(), 'email' => $email, 'role' => $target->role],
            companyId: $companyId,
        );

        $target->delete();

        // A deleted Owner leaves the company detail page's owner slot empty; the
        // company list is the safe landing spot in every case.
        $redirect = $companyId !== null
            ? redirect()->route('admin.clients.show', $companyId)
            : redirect()->route('admin.users.index');

        return $redirect->with('status', __('Deleted the account for :email.', ['email' => $email]));
    }

    /**
     * Email any Company_User a password-reset link. Uses Laravel's password
     * broker, which is deliberately neutral about whether the address exists.
     */
    public function sendPasswordReset(int $user): RedirectResponse
    {
        $target = $this->resolveUser($user);

        Password::sendResetLink(['email' => $target->email]);

        $this->audit->record(
            action: AuditLog::USER_PASSWORD_RESET_SENT,
            auditable: $target,
            summary: 'Sent a password reset to '.$target->email,
            context: ['user_id' => $target->getKey()],
            companyId: $target->company_id !== null ? (int) $target->company_id : null,
        );

        return $this->back($target)->with('status', __('A password reset link has been emailed to :email.', [
            'email' => $target->email,
        ]));
    }

    /**
     * Re-send the email-verification link to any Company_User. No-op with a
     * clear message if the user is already verified.
     */
    public function resendVerification(int $user): RedirectResponse
    {
        $target = $this->resolveUser($user);

        if ($target->hasVerifiedEmail()) {
            return $this->back($target)->with('status', __('The email (:email) is already verified.', [
                'email' => $target->email,
            ]));
        }

        // Fires immediately (VerifyEmailNow), not via the queue.
        $target->sendEmailVerificationNotification();

        $this->audit->record(
            action: AuditLog::USER_VERIFICATION_RESENT,
            auditable: $target,
            summary: 'Re-sent the email verification to '.$target->email,
            context: ['user_id' => $target->getKey()],
            companyId: $target->company_id !== null ? (int) $target->company_id : null,
        );

        return $this->back($target)->with('status', __('A verification email has been sent to :email.', [
            'email' => $target->email,
        ]));
    }

    /**
     * Resolve a Company_User by id, 404ing on a missing user or a Super_Admin
     * (which this surface never manages). This is the single place the
     * super-admin exclusion is enforced, so no action can touch one.
     */
    private function resolveUser(int $user): User
    {
        $target = User::query()
            ->where('is_super_admin', false)
            ->whereKey($user)
            ->first();

        if ($target === null) {
            throw new NotFoundHttpException;
        }

        return $target;
    }

    /**
     * Redirect back to the most useful page: the user's Company detail page when
     * they belong to a Company, else the platform user directory.
     */
    private function back(User $user): RedirectResponse
    {
        if ($user->company_id !== null) {
            return redirect()->route('admin.clients.show', (int) $user->company_id);
        }

        return redirect()->route('admin.users.index');
    }
}
