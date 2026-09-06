<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
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
    public function __construct(private readonly AuditLogger $audit) {}

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

        // A Company_User of a suspended Company must not be granted a session,
        // even with valid credentials. Tear the session down and reject the
        // login with an error. (Requirement 2.3)
        $company = Auth::user()->company;

        if ($company instanceof Company && $company->isSuspended()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => __('This account has been suspended.'),
            ]);
        }

        // A self-signed-up Owner must verify their email before they can sign
        // in. Super_Admins are provisioned out-of-band and invited users are
        // auto-verified on accept, so in practice only an unverified Owner is
        // rejected here. Tear the session down (so no usable session is granted)
        // and send them to the verification notice with a resend option, rather
        // than leaving them authenticated-but-bounced. The notice route
        // re-establishes a lightweight session on its own auth check.
        $user = Auth::user();

        if ($user instanceof User && ! $user->isSuperAdmin() && ! $user->hasVerifiedEmail()) {
            // Keep the session just long enough to show the notice + resend:
            // the user stays authenticated but `verified` gates the dashboard,
            // so they can go no further until they verify.
            $request->session()->regenerate();

            return redirect()->route('verification.notice');
        }

        $request->session()->regenerate();

        // Reset the idle-timeout window at sign-in. Without this, a returning
        // user whose stored `last_activity_at` is older than the idle threshold
        // authenticates successfully but is then immediately bounced by
        // SessionTimeout on the first post-login request ("session expired").
        // Stamping "now" here starts the idle window fresh from the sign-in.
        // (Requirement 3.11)
        Auth::user()->forceFill(['last_activity_at' => now()])->save();

        // Record the successful sign-in. The actor/Company are resolved from the
        // now-authenticated user by the logger; a Super_Admin has a null Company
        // so it lands only on the platform trail.
        $this->audit->record(
            action: AuditLog::AUTH_LOGIN_SUCCEEDED,
            summary: 'Signed in',
        );

        // Super_Admins land on the platform (super-admin) surface by default;
        // from there they can jump into a specific Company's dashboard. Company
        // users land on their own dashboard.
        if (Auth::user()->isSuperAdmin()) {
            return redirect()->intended('/admin');
        }

        return redirect()->intended('/dashboard');
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
