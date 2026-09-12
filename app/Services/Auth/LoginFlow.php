<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The shared "what happens after the password (and, if enabled, the second
 * factor) is accepted" flow, factored out of {@see \App\Http\Controllers\Auth\LoginController}
 * so both the password step and the two-factor challenge step converge on ONE
 * implementation of the suspended-Company / unverified-email / session-reset /
 * audit / landing-redirect rules.
 *
 * The session keys below carry a login that has passed the password check but
 * is waiting on a two-factor code. They hold only the user id and the
 * "remember me" choice — never credentials — and are cleared the moment the
 * challenge completes or is abandoned.
 */
class LoginFlow
{
    /** Session key: id of the user midway through a two-factor login. */
    public const PENDING_USER = 'auth.mfa.pending_user_id';

    /** Session key: the "remember me" choice captured at the password step. */
    public const PENDING_REMEMBER = 'auth.mfa.remember';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Stash a password-verified user as "pending two-factor" WITHOUT logging
     * them in, so the challenge screen can complete the login once a valid code
     * or recovery code is supplied. Returns the redirect to the challenge.
     */
    public function beginTwoFactorChallenge(Request $request, User $user, bool $remember): RedirectResponse
    {
        $request->session()->put(self::PENDING_USER, $user->getKey());
        $request->session()->put(self::PENDING_REMEMBER, $remember);

        return redirect()->route('two-factor.challenge');
    }

    /**
     * Finalise a login for an already-authenticated user (the guard login has
     * just happened, by password with no MFA, or by the challenge controller
     * after a valid code). Applies the suspended-Company and unverified-email
     * gates, resets the idle-timeout window, records the success, and returns
     * the correct landing redirect.
     *
     * Returns a redirect to the intended dashboard/admin surface on success, or
     * throws a ValidationException (suspended) / returns the verification-notice
     * redirect (unverified) exactly as the original LoginController did.
     */
    public function completeLogin(Request $request): RedirectResponse
    {
        // A Company_User of a suspended Company must not keep a session even
        // with valid credentials + second factor. (Requirement 2.3)
        $company = Auth::user()->company;

        if ($company instanceof Company && $company->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('This account has been suspended.'),
            ]);
        }

        // A self-signed-up Owner must verify their email before reaching the
        // dashboard. (See LoginController for the full rationale.)
        $user = Auth::user();

        if ($user instanceof User && ! $user->isSuperAdmin() && ! $user->hasVerifiedEmail()) {
            $request->session()->regenerate();

            return redirect()->route('verification.notice');
        }

        $request->session()->regenerate();

        // Reset the idle-timeout window at sign-in. (Requirement 3.11)
        Auth::user()->forceFill(['last_activity_at' => now()])->save();

        $this->audit->record(
            action: AuditLog::AUTH_LOGIN_SUCCEEDED,
            summary: 'Signed in',
        );

        if (Auth::user()->isSuperAdmin()) {
            return redirect()->intended('/admin');
        }

        // A Company_User who has not set up MFA and has not permanently
        // dismissed the reminder is nudged toward enabling it before landing on
        // the dashboard. The nudge page offers "set up now", "remind me next
        // time", and "don't remind me again".
        if (Auth::user()->shouldSeeMfaRecommendation()) {
            return redirect()->route('two-factor.recommend');
        }

        return redirect()->intended('/dashboard');
    }
}
