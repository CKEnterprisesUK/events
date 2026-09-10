<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;

/**
 * Super-admin owner / user recovery actions for a specific Company. These give a
 * Super_Admin the tools to unblock a tenant without impersonating them or
 * touching the database by hand:
 *
 *   - sendPasswordReset:  email the current Owner a password-reset link (the
 *                         IP-independent way to recover a locked-out / forgotten
 *                         -password Owner; login throttling is per-IP and clears
 *                         itself within a minute, so there is no DB lock to clear).
 *   - resendVerification: re-send the email-verification link to an Owner who
 *                         signed up but never verified and so cannot reach the
 *                         dashboard.
 *   - transferOwnership:  move the single Owner role from the current Owner to
 *                         another user in the same Company (e.g. the Owner left
 *                         the organisation).
 *
 * Not tenant-scoped — a Super_Admin operates across every Company. Users are
 * read with `withoutGlobalScopes()` and always constrained to the bound
 * `{company}`, so one Company's action can never touch another's users. Every
 * action is recorded on the audit trail against that Company.
 */
class CompanyUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Email the Company's current Owner a password-reset link. Recovers an Owner
     * who is locked out or has forgotten their password. Uses Laravel's password
     * broker, which is deliberately neutral about whether the address exists.
     */
    public function sendPasswordReset(Company $company): RedirectResponse
    {
        $owner = $this->currentOwner($company);

        if ($owner === null) {
            return $this->back($company)->with('error', __('This company has no owner to reset.'));
        }

        Password::sendResetLink(['email' => $owner->email]);

        $this->audit->record(
            action: AuditLog::OWNER_PASSWORD_RESET_SENT,
            auditable: $owner,
            summary: 'Sent a password reset to the owner ('.$owner->email.')',
            context: ['user_id' => $owner->getKey()],
            companyId: (int) $company->getKey(),
        );

        return $this->back($company)->with('status', __('A password reset link has been emailed to :email.', [
            'email' => $owner->email,
        ]));
    }

    /**
     * Re-send the email-verification link to the Company's Owner. No-op with a
     * clear message if the Owner is already verified.
     */
    public function resendVerification(Company $company): RedirectResponse
    {
        $owner = $this->currentOwner($company);

        if ($owner === null) {
            return $this->back($company)->with('error', __('This company has no owner.'));
        }

        if ($owner->hasVerifiedEmail()) {
            return $this->back($company)->with('status', __('The owner’s email (:email) is already verified.', [
                'email' => $owner->email,
            ]));
        }

        // Fires immediately (VerifyEmailNow), not via the queue.
        $owner->sendEmailVerificationNotification();

        $this->audit->record(
            action: AuditLog::USER_VERIFICATION_RESENT,
            auditable: $owner,
            summary: 'Re-sent the email verification to the owner ('.$owner->email.')',
            context: ['user_id' => $owner->getKey()],
            companyId: (int) $company->getKey(),
        );

        return $this->back($company)->with('status', __('A verification email has been sent to :email.', [
            'email' => $owner->email,
        ]));
    }

    /**
     * Transfer the single Owner role to another user in the same Company.
     *
     * The single-Owner invariant is enforced by a database UNIQUE index over a
     * generated `owner_company_id` column, so two rows in the same Company can
     * never both be `owner`. We therefore DEMOTE the current Owner (to admin)
     * and PROMOTE the target within one transaction, demoting first so the
     * unique index is never transiently violated. The target must be an existing,
     * non-super-admin user of THIS Company and not already the Owner.
     */
    public function transferOwnership(Request $request, Company $company): RedirectResponse
    {
        $currentOwner = $this->currentOwner($company);

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                // Must be a user of THIS company (tenant-safe: scoped by company_id).
                Rule::exists('users', 'id')->where(fn ($q) => $q
                    ->where('company_id', $company->getKey())
                    ->where('is_super_admin', false)),
            ],
        ]);

        /** @var User|null $target */
        $target = User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->whereKey($validated['user_id'])
            ->first();

        if ($target === null) {
            return $this->back($company)->withErrors(['user_id' => __('That user is not part of this company.')]);
        }

        if ($currentOwner !== null && $target->is($currentOwner)) {
            return $this->back($company)->withErrors(['user_id' => __('That user is already the owner.')]);
        }

        DB::transaction(function () use ($currentOwner, $target): void {
            // Demote first so the single-owner unique index is never violated.
            if ($currentOwner !== null) {
                $currentOwner->role = User::ROLE_ADMIN;
                $currentOwner->save();
            }

            $target->role = User::ROLE_OWNER;
            $target->save();
        });

        $this->audit->record(
            action: AuditLog::OWNER_TRANSFERRED,
            auditable: $target,
            summary: 'Transferred ownership to '.$target->email
                .($currentOwner !== null ? ' (from '.$currentOwner->email.')' : ''),
            context: [
                'from_user_id' => $currentOwner?->getKey(),
                'to_user_id' => $target->getKey(),
            ],
            companyId: (int) $company->getKey(),
        );

        return $this->back($company)->with('status', __('Ownership transferred to :name (:email).', [
            'name' => $target->name,
            'email' => $target->email,
        ]));
    }

    /**
     * The Company's current Owner, read across the tenant scope and constrained
     * to this Company. Null if the Company has no Owner (shouldn't happen in
     * normal operation, but handled defensively).
     */
    private function currentOwner(Company $company): ?User
    {
        return User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('role', User::ROLE_OWNER)
            ->first();
    }

    /**
     * Redirect back to this Company's client detail page.
     */
    private function back(Company $company): RedirectResponse
    {
        return redirect()->route('admin.clients.show', $company);
    }
}
