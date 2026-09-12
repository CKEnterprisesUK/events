<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Self-service two-factor (TOTP) management for the authenticated Company_User,
 * living alongside the profile page. Handles the enrolment lifecycle:
 *
 *   - `enable`    : begin enrolment (generate secret + recovery codes) and show
 *                   the QR/secret + a code field to confirm.
 *   - `confirm`   : verify the first code and activate MFA.
 *   - `disable`   : turn MFA off (password-confirmed).
 *   - `recovery`  : regenerate recovery codes (password-confirmed).
 *
 * Like {@see \App\Http\Controllers\ProfileController}, this only ever acts on
 * the acting user's OWN record, so no Company role gate applies. Sensitive
 * actions (enable/disable/regenerate) require the current password so a walk-up
 * on an unlocked session cannot silently change MFA. The freshly generated
 * secret and recovery codes are surfaced to the view via one-shot flash data,
 * never persisted in the session beyond the redirect.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticationService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Begin (or restart) enrolment. Requires the current password. Generates a
     * new pending secret + recovery codes and returns to the profile page with
     * the QR/secret and codes flashed so the user can scan and confirm. MFA is
     * NOT active until confirm() succeeds.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $user = $request->user();

        $this->twoFactor->startEnrolment($user);

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('mfa_setup', [
                'qr' => $this->twoFactor->qrCodeInline($user),
                'secret' => $this->twoFactor->secretForDisplay($user),
                'recovery_codes' => $user->two_factor_recovery_codes,
            ])
            ->withFragment('two-factor');
    }

    /**
     * Confirm enrolment with the first TOTP code. On success MFA is active and
     * the login challenge will fire from now on; on a bad code we bounce back
     * to the setup panel with an error and the pending secret intact so the
     * user can retry without restarting.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $this->twoFactor->confirm($user, $data['code'])) {
            // Re-surface the setup panel so the user can try the code again
            // against the same pending secret.
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Check your authenticator app and try again.'),
            ])->redirectTo(route('dashboard.profile.edit').'#two-factor');
        }

        $this->audit->record(
            action: AuditLog::AUTH_MFA_ENABLED,
            auditable: $user,
            summary: 'Enabled two-factor authentication',
        );

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('mfa_status', 'Two-factor authentication is now on.')
            ->withFragment('two-factor');
    }

    /**
     * Turn MFA off. Requires the current password so a hijacked, password-less
     * session cannot strip a user's second factor.
     */
    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $user = $request->user();

        $this->twoFactor->disable($user);

        $this->audit->record(
            action: AuditLog::AUTH_MFA_DISABLED,
            auditable: $user,
            summary: 'Disabled two-factor authentication',
        );

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('mfa_status', 'Two-factor authentication is now off.')
            ->withFragment('two-factor');
    }

    /**
     * Regenerate the one-time recovery codes, discarding the old set. Requires
     * the current password and that MFA is actually enabled. The new codes are
     * flashed for a single render so the user can note them down.
     */
    public function regenerateRecoveryCodes(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages([
                'current_password' => __('Enable two-factor authentication before generating recovery codes.'),
            ])->redirectTo(route('dashboard.profile.edit').'#two-factor');
        }

        $this->twoFactor->regenerateRecoveryCodes($user);

        $this->audit->record(
            action: AuditLog::AUTH_MFA_RECOVERY_CODES_REGENERATED,
            auditable: $user,
            summary: 'Regenerated two-factor recovery codes',
        );

        return redirect()
            ->route('dashboard.profile.edit')
            ->with('mfa_status', 'New recovery codes generated. Your old codes no longer work.')
            ->with('mfa_recovery_codes', $user->two_factor_recovery_codes)
            ->withFragment('two-factor');
    }
}
