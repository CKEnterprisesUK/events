<?php

namespace App\Http\Middleware;

use App\Services\PendingMigrations;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a Super_Admin loads the admin area and there are migrations that have
 * not been run, route them straight to the build page (/admin/ops) so they can
 * apply the schema without a terminal.
 *
 * This is the "taken straight to a build page" behaviour: after an
 * "Update from Remote" pulls code that adds a migration, the very next admin
 * navigation lands on the build page instead of a view that might depend on a
 * column that doesn't exist yet.
 *
 * It runs behind `auth` + `super.admin`, so by the time it executes the user is
 * already a confirmed Super_Admin — the group guards access; this middleware
 * only decides *where within the admin surface* to send them.
 *
 * LOOP + NOISE SAFETY (why the guards below matter):
 *   * Only GET requests are redirected. POSTs (the migrate / clear-caches form
 *     submissions themselves) pass through untouched, otherwise clicking
 *     "Run migrations" would bounce back to the page instead of running.
 *   * The ops routes are exempt. Redirecting /admin/ops to /admin/ops is an
 *     infinite loop; redirecting the migrate POST would prevent the fix.
 *   * Only "true" navigations redirect — not AJAX / JSON / Livewire polls — so
 *     a background poll doesn't yank the page away.
 *   * PendingMigrations fails safe (returns "none pending" if it can't check),
 *     so a broken detector can never trap the operator here.
 */
class RedirectToBuildPageWhenMigrationsPending
{
    /**
     * Route names that must never be redirected away from — the build page and
     * its own actions. (Named-route checks are robust to prefix changes.)
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'admin.ops.index',
        'admin.ops.migrate',
        'admin.ops.reseed',
        'admin.ops.rebuild-caches',
    ];

    public function __construct(private readonly PendingMigrations $pendingMigrations)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldRedirect($request)) {
            return redirect()
                ->route('admin.ops.index')
                ->with('ops_notice', 'There are pending database migrations. Run them to finish deploying the update.');
        }

        return $next($request);
    }

    private function shouldRedirect(Request $request): bool
    {
        // Only plain page loads: never intercept form submissions or API/AJAX.
        if (! $request->isMethod('GET') || $request->expectsJson() || $request->ajax()) {
            return false;
        }

        // Never redirect the build page or its actions onto themselves.
        $routeName = $request->route()?->getName();
        if ($routeName !== null && in_array($routeName, self::EXEMPT_ROUTES, true)) {
            return false;
        }

        // Cheap, cached check — safe to call on every admin request.
        return $this->pendingMigrations->hasPending();
    }
}
