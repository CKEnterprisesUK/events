<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\LoginFlow;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Handles Company_User authentication: showing the login form, establishing a
 * session on valid credentials, and logging out.
 *
 * Authentication uses Laravel's session-based `web` guard against the `users`
 * table. Role scoping, tenant scoping, and the single-Owner invariant are added
 * in later tasks (2.x/4.x); this task provides the login/logout/session base.
 *
 * Requirement 3.9 (invalid credentials rejected) is honoured here by returning
 * an invalid-credentials error and granting no session.
 */
class LoginController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LoginFlow $loginFlow,
    ) {}

    /**
     * Show the login form.
     */
    public function show(): View
    {
        return view('auth.login');
    }

    /**
     * Attempt to authenticate a Company_User and establish a session.
     */
    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            // Volume guard: only record a failed attempt when the email matches
            // a real account, so random enumeration/credential-stuffing noise
            // (which rate limiting already caps) does not bloat the trail. The
            // attempt has no authenticated actor; attribute it to the target
            // account's Company so it surfaces on that Company's activity view.
            $target = User::where('email', $credentials['email'])->first();

            if ($target !== null) {
                $this->audit->record(
                    action: AuditLog::AUTH_LOGIN_FAILED,
                    auditable: $target,
                    summary: 'Failed sign-in for '.$target->email,
                    context: ['email' => $target->email],
                    companyId: $target->company_id !== null ? (int) $target->company_id : null,
                );
            }

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $user = Auth::user();

        // If the user has active two-factor authentication, the password is
        // only the FIRST factor. Drop the guard session immediately (so no
        // usable session exists until the second factor is proven), stash the
        // user as "pending two-factor", and hand off to the challenge screen.
        // The challenge controller re-logs the user in and runs the shared
        // completion flow (suspended / unverified / audit / redirect) on
        // success. (MFA login challenge)
        if ($user instanceof User && $user->hasTwoFactorEnabled()) {
            Auth::logout();

            return $this->loginFlow->beginTwoFactorChallenge($request, $user, $remember);
        }

        // No second factor: finalise the login now. LoginFlow applies the
        // suspended-Company and unverified-email gates, resets the idle-timeout
        // window, records the sign-in, and returns the correct landing redirect
        // (including the post-login MFA recommendation nudge).
        return $this->loginFlow->completeLogin($request);
    }

    /**
     * Log the Company_User out and invalidate the session.
     */
    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
