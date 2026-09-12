<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\QrService;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service two-factor (TOTP) management for the authenticated Company_User.
 * The enrolment flow lives on its OWN dedicated screen (not the profile page):
 *
 *   - `enable`  : begin enrolment (generate a pending secret + recovery codes),
 *                 then redirect to the setup screen. Password-confirmed.
 *   - `setup`   : the standalone setup screen — shows the scannable QR image,
 *                 the manual key, the recovery codes and the confirm form.
 *   - `qr`      : streams the QR as a real PNG (via {@see QrService}) so the
 *                 image loads reliably, matching the app's other QR codes.
 *   - `confirm` : verify the first code and activate MFA.
 *   - `disable` : turn MFA off (password-confirmed).
 *   - `recovery`: regenerate recovery codes (password-confirmed).
 *
 * Like {@see \App\Http\Controllers\ProfileController}, this only ever acts on
 * the acting user's OWN record, so no Company role gate applies. Sensitive
 * actions (enable/disable/regenerate) require the current password so a walk-up
 * on an unlocked session cannot silently change MFA.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticationService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Begin (or restart) enrolment. Requires the current password. Generates a
     * new pending secret + recovery codes, then sends the user to the dedicated
     * setup screen. MFA is NOT active until confirm() succeeds.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $this->twoFactor->startEnrolment($request->user());

        return redirect()->route('dashboard.profile.two-factor.setup');
    }

    /**
     * The standalone MFA setup screen. Requires a pending (unconfirmed) secret;
     * if the user has no enrolment in progress they are sent back to the
     * profile page to start one. If MFA is already fully enabled, likewise
     * bounce to the profile page (nothing to set up).
     */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return redirect()->route('dashboard.profile.edit')->withFragment('two-factor');
        }

        return view('dashboard.profile.two-factor-setup', [
            'secret' => $this->twoFactor->secretForDisplay($user),
            'recoveryCodes' => $user->two_factor_recovery_codes ?? [],
        ]);
    }

    /**
     * Stream the pending enrolment's provisioning URI as a PNG QR image.
     * Rendered with the app's {@see QrService} (endroid/qr-code + GD), so it
     * behaves exactly like the storefront/event QR codes and loads reliably in
     * an <img> tag. 404s when there is no pending secret to encode.
     */
    public function qr(Request $request, QrService $qr): Response
    {
        $user = $request->user();

        $uri = $this->twoFactor->otpauthUri($user);

        abort_if($uri === null, 404);

        try {
            $png = $qr->png($uri, 400);
        } catch (\Throwable $e) {
            abort(500, 'QR code generation is unavailable on this server.');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            // Sensitive + per-session: never cache the secret-bearing QR.
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * Confirm enrolment with the first TOTP code. On success MFA is active and
     * the login challenge fires from now on; on a bad code we bounce back to
     * the setup screen with an error and the pending secret intact so the user
     * can retry without restarting.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $this->twoFactor->confirm($user, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Check your authenticator app and try again.'),
            ])->redirectTo(route('dashboard.profile.two-factor.setup'));
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
