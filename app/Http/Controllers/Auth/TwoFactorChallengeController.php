<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\LoginFlow;
use App\Services\AuditLogger;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The second step of a two-factor login. A user who has passed the password
 * check is held as "pending two-factor" in the session (see {@see LoginFlow})
 * WITHOUT an authenticated guard session; this controller accepts either a
 * time-based (TOTP) code or a one-time recovery code, and on success logs the
 * user in and runs the shared login-completion flow.
 *
 * The whole surface is behind the `guest` middleware: it is reached only in the
 * window between a correct password and a proven second factor, and there is no
 * authenticated user during it. If the pending marker is missing (deep link,
 * expired session, back button after completing), the user is sent back to the
 * login form.
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly TwoFactorAuthenticationService $twoFactor,
        private readonly LoginFlow $loginFlow,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Show the challenge form. Redirects to login if there is no pending
     * two-factor user in the session.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if ($this->pendingUser($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    /**
     * Verify the submitted TOTP code or recovery code. On success, log the
     * pending user in, clear the pending markers, and complete the login. On
     * failure, bounce back to the challenge with an error (the pending marker
     * survives so the user can retry).
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if ($user === null) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $code = $data['code'] ?? null;
        $recoveryCode = $data['recovery_code'] ?? null;

        if (($code === null || $code === '') && ($recoveryCode === null || $recoveryCode === '')) {
            throw ValidationException::withMessages([
                'code' => __('Enter your authentication code or a recovery code.'),
            ]);
        }

        $usedRecoveryCode = false;

        if ($code !== null && $code !== '') {
            $passed = $this->twoFactor->verifyTotp($user, $code);
        } else {
            $passed = $this->twoFactor->useRecoveryCode($user, (string) $recoveryCode);
            $usedRecoveryCode = $passed;
        }

        if (! $passed) {
            $this->audit->record(
                action: AuditLog::AUTH_MFA_CHALLENGE_FAILED,
                auditable: $user,
                summary: 'Failed two-factor challenge',
                companyId: $user->company_id !== null ? (int) $user->company_id : null,
            );

            throw ValidationException::withMessages([
                'code' => __('That code is not valid. Try again, or use a recovery code.'),
            ]);
        }

        // Second factor proven: log the user in with the remembered "remember
        // me" choice from the password step, then clear the pending markers.
        $remember = (bool) $request->session()->pull(LoginFlow::PENDING_REMEMBER, false);
        $request->session()->pull(LoginFlow::PENDING_USER);

        Auth::login($user, $remember);

        $this->audit->record(
            action: $usedRecoveryCode
                ? AuditLog::AUTH_MFA_RECOVERY_CODE_USED
                : AuditLog::AUTH_MFA_CHALLENGE_SUCCEEDED,
            auditable: $user,
            summary: $usedRecoveryCode
                ? 'Signed in with a two-factor recovery code'
                : 'Passed two-factor challenge',
        );

        return $this->loginFlow->completeLogin($request);
    }

    /**
     * The user held mid-login by the pending-two-factor session marker, or null
     * if there is none (or it points at a now-missing/MFA-disabled user).
     */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(LoginFlow::PENDING_USER);

        if ($id === null) {
            return null;
        }

        $user = User::find($id);

        // Defence in depth: only honour the marker for a user who still has MFA
        // active. If MFA was disabled out-of-band, drop the stale marker.
        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            $request->session()->forget([LoginFlow::PENDING_USER, LoginFlow::PENDING_REMEMBER]);

            return null;
        }

        return $user;
    }
}
