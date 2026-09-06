<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks login and existing sessions for Company_Users whose Company is
 * suspended.
 *
 * A Super_Admin can suspend a Company at any time (Requirement 20.3). While a
 * Company is suspended:
 *   - a Company_User of that Company must not be able to log in (Requirement
 *     2.3), and
 *   - an already-authenticated Company_User must not be able to keep operating
 *     — in particular they must not be able to reach any ticket-selling
 *     surface (Requirement 2.4).
 *
 * This middleware enforces both by inspecting the authenticated user's owning
 * Company on every authenticated request: if that Company is suspended the
 * session is torn down and access is denied. Wiring it into the `auth`
 * middleware group means the very next authenticated request after a suspension
 * is rejected, so suspension takes effect immediately without waiting for the
 * session to expire.
 *
 * The middleware is deliberately resilient. Company_Users gain a `company_id`
 * (and therefore an owning Company) in a later task; until then, or for any
 * user with no owning Company (e.g. a Super_Admin), there is no Company to
 * suspend and the request is allowed through. Only a *resolved, suspended*
 * Company blocks the request.
 *
 * When a Super_Admin unsuspends a Company its users can log in and operate
 * again with no further change here, because this check reads the Company's
 * live status on each request. (Requirement 2.5.)
 */
class EnsureCompanyActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $company = $this->authenticatedUserCompany();

        if ($company !== null && $company->isSuspended()) {
            return $this->denySuspended($request);
        }

        return $next($request);
    }

    /**
     * The owning Company of the currently authenticated user, or null when
     * there is no authenticated user or the user has no owning Company.
     *
     * Reads the Company defensively so it works before Company_Users carry a
     * `company_id` and never throws when the association is absent.
     */
    private function authenticatedUserCompany(): ?Company
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        // The `company` relation is added when Company_Users gain a
        // `company_id`. Guard against its absence so this middleware is safe to
        // wire in ahead of that schema change.
        if (! method_exists($user, 'company')) {
            return null;
        }

        $company = $user->company;

        return $company instanceof Company ? $company : null;
    }

    /**
     * Tear down the session and deny the request for a suspended Company.
     *
     * On a login attempt this surfaces as a validation error on the login form
     * (Requirement 2.3); on any other authenticated request the user is logged
     * out and redirected to the login screen (Requirement 2.4).
     */
    private function denySuspended(Request $request): Response
    {
        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = __('This account has been suspended.');

        if ($this->expectsJson($request)) {
            abort(403, $message);
        }

        if ($this->isLoginAttempt($request)) {
            throw ValidationException::withMessages([
                'email' => $message,
            ]);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }

    private function expectsJson(Request $request): bool
    {
        return $request->expectsJson() || $request->is('api/*');
    }

    private function isLoginAttempt(Request $request): bool
    {
        return $request->routeIs('login') || $request->is('login');
    }
}
