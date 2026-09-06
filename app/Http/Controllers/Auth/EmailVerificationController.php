<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Handles the email-verification flow required before a self-signed-up Owner
 * can use the dashboard. (Option A: the Company + Owner are created at signup,
 * but the account is gated behind email verification.)
 *
 * Three endpoints, matching Laravel's built-in verification route names so the
 * framework's `VerifyEmail` notification URL and the `verified` middleware
 * resolve correctly:
 *   - `verification.notice` — the "check your email" page shown to an
 *     authenticated but unverified user.
 *   - `verification.verify` — the signed link from the email; marks the address
 *     verified and sends the user on to the dashboard.
 *   - `verification.send`   — resends the verification email (throttled).
 *
 * Invited users are auto-verified on invitation accept (the token proves email
 * ownership), so they never pass through here.
 */
class EmailVerificationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Show the "verify your email" notice. If the user is already verified,
     * send them straight to the dashboard.
     */
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->intended('/dashboard')
            : view('auth.verify-email');
    }

    /**
     * Handle the signed verification link. The framework's
     * EmailVerificationRequest validates the signature and that the id/hash
     * match the authenticated user, then we mark the address verified.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/dashboard');
        }

        if ($request->user()->markEmailAsVerified()) {
            $this->audit->record(
                action: AuditLog::AUTH_EMAIL_VERIFIED,
                summary: 'Verified email address',
            );
        }

        return redirect()->intended('/dashboard')
            ->with('status', 'Your email address has been verified.');
    }

    /**
     * Resend the verification email to the authenticated user.
     */
    public function resend(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended('/dashboard');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'A fresh verification link has been sent to your email address.');
    }
}
