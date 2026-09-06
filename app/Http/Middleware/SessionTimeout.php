<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invalidates authenticated sessions that have been idle for 30 minutes or
 * longer, forcing re-authentication before any further action. (Requirement
 * 3.11)
 *
 * On every authenticated request this middleware compares the user's
 * `last_activity_at` timestamp against the current time:
 *
 *   - If the idle duration is >= 30 minutes the session is torn down and the
 *     request is denied — a login attempt is required before anything else can
 *     happen. This is the boundary: exactly 30 minutes of inactivity already
 *     requires re-auth.
 *   - Otherwise the request proceeds and `last_activity_at` is refreshed to
 *     "now", so activity continually resets the idle window.
 *
 * A user with no recorded `last_activity_at` (e.g. one that has never had it
 * stamped) is treated as active for this request and simply gets the timestamp
 * seeded; the idle window starts from that point. Wiring this into the
 * authenticated route group means the very next request after the window
 * elapses is rejected, so the timeout takes effect without waiting for the
 * cookie/session lifetime to expire.
 */
class SessionTimeout
{
    /**
     * Idle duration, in minutes, at or beyond which a session is invalidated.
     * (Requirement 3.11)
     */
    public const IDLE_TIMEOUT_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $now = Carbon::now();
        $lastActivity = $user->last_activity_at;

        if ($lastActivity !== null && $this->isIdleExpired($lastActivity, $now)) {
            return $this->denyExpired($request);
        }

        // Still within the active window (or first request): record activity so
        // the idle timer resets from now.
        $user->forceFill(['last_activity_at' => $now])->save();

        return $next($request);
    }

    /**
     * Whether the time elapsed since the last recorded activity is at least the
     * idle-timeout threshold. The boundary is inclusive: exactly 30 minutes of
     * inactivity is already expired. (Requirement 3.11)
     */
    private function isIdleExpired(Carbon $lastActivity, Carbon $now): bool
    {
        if ($lastActivity->greaterThan($now)) {
            return false;
        }

        // Compare in whole seconds so the 30-minute boundary is exact and not
        // subject to fractional-minute truncation: >= 1800s is expired.
        return $lastActivity->diffInSeconds($now) >= self::IDLE_TIMEOUT_MINUTES * 60;
    }

    /**
     * Tear down the idle session and require re-authentication. On a normal web
     * request the user is redirected to login; JSON/API surfaces receive a 401.
     */
    private function denyExpired(Request $request): Response
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = __('Your session has expired due to inactivity. Please sign in again.');

        if ($request->expectsJson() || $request->is('api/*')) {
            abort(401, $message);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
