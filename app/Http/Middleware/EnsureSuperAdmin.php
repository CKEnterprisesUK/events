<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the separate super-admin surface (the reserved `/admin` prefix).
 *
 * The super-admin dashboard is deliberately separate from the Company
 * dashboards, both in routing (a reserved prefix that resolves no Company) and
 * in authorisation: access is granted purely on the `is_super_admin` flag and
 * NOT via the Company role matrix. A Company_User — whatever their Company role
 * — has `is_super_admin = false` and is denied; a guest is sent to log in.
 * (Requirements 20.1, 20.7)
 *
 * This runs behind the `auth` middleware, which already redirects guests to the
 * login screen. So by the time this middleware runs there is an authenticated
 * user; the only decision left is whether that user is a Super_Admin. Anyone
 * who is not is denied with a 403 — the super-admin surface exists for CK
 * Enterprises operators alone. (Requirement 20.7)
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
