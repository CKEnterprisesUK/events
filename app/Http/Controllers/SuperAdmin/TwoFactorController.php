<?php

namespace App\Http\Controllers\SuperAdmin;

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
 * Self-service two-factor (TOTP) management for a CK Enterprises Super_Admin,
 * living on the Super_Admin platform surface (`/admin/profile`).
 *
 * This is the Super_Admin counterpart to {@see \App\Http\Controllers\Auth\TwoFactorController}
 * (which serves Company_Users under `/dashboard`). It reuses the SAME role-
 * agnostic {@see TwoFactorAuthenticationService}; only the redirect/view names
 * differ (`admin.profile.*` instead of `dashboard.profile.*`). The whole
 * `/admin` group is already gated to Super_Admins by the `super.admin`
 * middleware, so — matching the SuperAdmin controller convention — there are no
 * per-action Gate calls here; it acts only on the acting Super_Admin's OWN
 * record. Sensitive actions still require the current password so a walk-up on
 * an unlocked session cannot silently change MFA.
 *
 * The login-time challenge and the User model checks are already role-agnostic,
 * so once a Super_Admin confirms enrolment here they are challenged for a code
 * at their next login with no further wiring.
 */
class TwoFactorController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticationService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Begin (or restart) enrolment. Requires the current password. Generates a
     * new pending secret + recovery codes, then sends the Super_Admin to the
     * dedicated setup screen. MFA is NOT active until confirm() succeeds.
     */
    public function enable(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
        ]);

        $this->twoFactor->startEnrolment($request->user());

        return redirect()->route('admin.profile.two-factor.setup');
    }

    /**
     * The standalone MFA setup screen. Requires a pending (unconfirmed) secret;
     * if there is no enrolment in progress, or MFA is already fully enabled,
     * bounce back to the security page.
     */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return redirect()->route('admin.profile.edit')->withFragment('two-factor');
        }

        return view('admin.profile.two-factor-setup', [
            'secret' => $this->twoFactor->secretForDisplay($user),
            'recoveryCodes' => $user->two_factor_recovery_codes ?? [],
        ]);
    }

    /**
     * Stream the pending enrolment's provisioning URI as a PNG QR image, using
     * the app's {@see QrService} (endroid/qr-code + GD) so it loads reliably in
     * an <img> tag. 404s when there is no pending secret to encode.
     */
    public function qr(Request $request, QrService $qr): Response
    {
        $uri = $this->twoFactor->otpauthUri($request->user());

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
     * the setup screen with an error and the pending secret intact.
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
            ])->redirectTo(route('admin.profile.two-factor.setup'));
        }

        $this->audit->record(
            action: AuditLog::AUTH_MFA_ENABLED,
            auditable: $user,
            summary: 'Enabled two-factor authentication',
        );

        return redirect()
            ->route('admin.profile.edit')
            ->with('mfa_status', 'Two-factor authentication is now on.')
            ->withFragment('two-factor');
    }

    /**
     * Turn MFA off. Requires the current password so a hijacked, password-less
     * session cannot strip the second factor.
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
            ->route('admin.profile.edit')
            ->with('mfa_status', 'Two-factor authentication is now off.')
            ->withFragment('two-factor');
    }

    /**
     * Regenerate the one-time recovery codes, discarding the old set. Requires
     * the current password and that MFA is actually enabled. The new codes are
     * flashed for a single render so they can be noted down.
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
            ])->redirectTo(route('admin.profile.edit').'#two-factor');
        }

        $this->twoFactor->regenerateRecoveryCodes($user);

        $this->audit->record(
            action: AuditLog::AUTH_MFA_RECOVERY_CODES_REGENERATED,
            auditable: $user,
            summary: 'Regenerated two-factor recovery codes',
        );

        return redirect()
            ->route('admin.profile.edit')
            ->with('mfa_status', 'New recovery codes generated. Your old codes no longer work.')
            ->with('mfa_recovery_codes', $user->two_factor_recovery_codes)
            ->withFragment('two-factor');
    }
}
