<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The post-login "two-factor is recommended" nudge shown to a signed-in
 * Company_User who has not enabled MFA and has not permanently dismissed the
 * reminder (see {@see \App\Models\User::shouldSeeMfaRecommendation()}). The
 * login flow redirects here instead of straight to the dashboard.
 *
 * The user has three choices:
 *   - set up now      → link to the profile two-factor section (a plain link,
 *                        handled by the view, no action here);
 *   - remind me later → {@see dismissOnce()} sends them to the dashboard for
 *                        this session; the nudge returns at the next login;
 *   - never remind    → {@see dismissForever()} stamps `mfa_prompt_dismissed_at`
 *                        so the nudge never shows again.
 *
 * Authenticated (not `guest`): it sits after a completed login. A user who has
 * since enabled MFA no longer qualifies and is bounced to the dashboard.
 */
class TwoFactorRecommendationController extends Controller
{
    /**
     * Show the recommendation. If the user has meanwhile enabled MFA (or should
     * no longer see it), skip straight to the dashboard.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->shouldSeeMfaRecommendation()) {
            return redirect()->intended('/dashboard');
        }

        return view('auth.two-factor-recommend');
    }

    /**
     * "Remind me next time": take no persistent action, just proceed to the
     * dashboard. The nudge will show again at the next login because
     * `mfa_prompt_dismissed_at` stays NULL.
     */
    public function dismissOnce(Request $request): RedirectResponse
    {
        return redirect()->intended('/dashboard');
    }

    /**
     * "Don't remind me again": stamp the dismissal so the nudge never reappears
     * for this user (until they enable MFA, which supersedes it anyway).
     */
    public function dismissForever(Request $request): RedirectResponse
    {
        $request->user()->forceFill(['mfa_prompt_dismissed_at' => now()])->save();

        return redirect()->intended('/dashboard');
    }
}
