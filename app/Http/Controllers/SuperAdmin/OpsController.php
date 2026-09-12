<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\PendingMigrations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * Database "build" operations, run from the browser (no SSH/terminal).
 *
 * On this shared cPanel host there is no terminal and `proc_open`/`shell_exec`
 * are disabled, so schema/seed work cannot be driven from a shell. cPanel Git
 * "Deploy" can run artisan, but its deploy step keeps dirtying the checkout and
 * blocking the next deploy. These actions instead run migrate/seed IN-PROCESS
 * from an authenticated Super_Admin request — the same PHP process that already
 * serves the app — so nothing shells out and the git checkout is never touched.
 *
 * UNIFIED WORKFLOW (identical on pre-prod AND production):
 *   1. git push (pre-prod) or merge to main + push (prod)
 *   2. cPanel Git → "Update from Remote"  (pulls code; runs NO deploy tasks, so
 *      it cannot dirty the working tree)
 *   3. The next time a Super_Admin loads /admin they are routed to this build
 *      page automatically IF migrations are pending
 *      (see RedirectToBuildPageWhenMigrationsPending). Click "Run migrations",
 *      then "Clear caches".
 *
 * WHAT DIFFERS BY ENVIRONMENT
 *   * "Run migrations" (migrate --force) and "Clear caches" run in EVERY
 *     environment, production included. Migrations are forward-only and, in
 *     production, are additionally gated behind an explicit confirmation in the
 *     UI plus a reminder to take a cPanel database backup first.
 *   * "Rebuild sample data" (migrate:fresh --seed) is DESTRUCTIVE and is
 *     PERMANENTLY refused in production — see reseed()/productionOnlyGuard().
 *     It exists only to give pre-prod a clean, synthetic dataset.
 *
 * GUARDS
 *   * Route group already requires auth + super.admin (Super_Admin only).
 *   * reseed() refuses whenever APP_ENV=production (allow-list, fails safe), so
 *     it can never wipe the production database even if the route is reached.
 *   * `reseed` delegates to the `preprod:seed` command, which additionally
 *     refuses unless the resolved DB is really mysql/mariadb (blocks the
 *     sqlite-fallback trap) — see routes/console.php.
 */
class OpsController extends Controller
{
    public function __construct(private readonly PendingMigrations $pendingMigrations) {}

    /**
     * Build/status page: current migration state + row counts, with the action
     * buttons. Read-only.
     */
    public function index(): View
    {
        // Capture `migrate:status` output for display. This is the accurate,
        // detailed view; the cheap PendingMigrations check drives the redirect.
        Artisan::call('migrate:status');
        $status = Artisan::output();

        $counts = [];
        foreach (['companies', 'users', 'events', 'orders', 'tickets'] as $table) {
            $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
        }

        return view('admin.ops.index', [
            // Migrate + clear-caches are available everywhere now; only the
            // destructive reseed is production-blocked, so the view hides it and
            // shows a backup warning on the migrate button instead.
            'isProduction' => app()->environment('production'),
            'reseedBlocked' => $this->productionOnlyGuard(),
            'connection' => config('database.default'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'migrationStatus' => $status,
            'pendingMigrations' => $this->pendingMigrations->pending(useCache: false),
            'counts' => $counts,
        ]);
    }

    /**
     * Run pending migrations (php artisan migrate --force), in-process.
     *
     * Allowed in ALL environments. Migrations are forward-only; the destructive
     * wipe lives in reseed() and is production-blocked. In production the view
     * gates this behind a typed confirmation + backup reminder before the form
     * is submitted.
     */
    public function migrate(): RedirectResponse
    {
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            return back()->with('ops_error', 'Migrate failed: '.$e->getMessage());
        }

        // The schema changed, so the cheap "pending" verdict is now stale —
        // drop it so the banner/redirect clears on the next request.
        $this->pendingMigrations->forget();

        return back()->with('ops_status', trim(Artisan::output()) ?: 'Migrations run.');
    }

    /**
     * Rebuild the sample data. Delegates to the guarded `preprod:seed` command.
     *
     * DESTRUCTIVE and PRODUCTION-BLOCKED. With `fresh=1` it wipes and rebuilds
     * (migrate:fresh --seed); otherwise it seeds only when the database is empty.
     */
    public function reseed(Request $request): RedirectResponse
    {
        if ($msg = $this->productionOnlyGuard()) {
            return back()->with('ops_error', $msg);
        }

        $fresh = $request->boolean('fresh');

        try {
            Artisan::call('preprod:seed', $fresh ? ['--fresh' => true] : []);
        } catch (\Throwable $e) {
            return back()->with('ops_error', 'Reseed failed: '.$e->getMessage());
        }

        $this->pendingMigrations->forget();

        return back()->with('ops_status', trim(Artisan::output()) ?: 'Seed complete.');
    }

    /**
     * Clear the compiled route / config / view / event caches, in-process.
     *
     * This is the fix for the deploy model where "Update from Remote" pulls new
     * code but runs NO deploy tasks (see .cpanel.yml): a stale compiled route
     * cache would otherwise keep the app on the OLD route table, so a route
     * added in the pulled code resolves as "Route [...] not defined" until the
     * cache is dropped. Click this after every code pull.
     *
     * Clearing caches is NON-DESTRUCTIVE (it never touches data or schema) and
     * runs in every environment, production included.
     */
    public function rebuildCaches(): RedirectResponse
    {
        $messages = [];

        // Order matters a little: clear config first so subsequent commands see
        // fresh config. Each is wrapped so one failure doesn't abort the rest.
        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            try {
                Artisan::call($command);
                $messages[] = $command.': '.(trim(Artisan::output()) ?: 'done');
            } catch (\Throwable $e) {
                $messages[] = $command.': FAILED — '.$e->getMessage();
            }
        }

        return back()->with('ops_status', "Caches cleared.\n".implode("\n", $messages));
    }

    /**
     * Returns a refusal message when we are in production, or null otherwise.
     *
     * Used ONLY by the destructive reseed action. This is an ALLOW-LIST, not a
     * deny-list: reseed is permitted ONLY when APP_ENV is one of the explicit
     * pre-prod/dev names below. Anything else — including `production` OR a
     * missing/misconfigured APP_ENV — fails SAFE and refuses. The guard is based
     * on APP_ENV (the app's environment), never the domain name: the pre-prod
     * host sets APP_ENV=staging (see PREPROD.md) while production keeps
     * APP_ENV=production.
     */
    private function productionOnlyGuard(): ?string
    {
        $allowed = ['local', 'staging', 'preprod', 'development'];

        return app()->environment($allowed)
            ? null
            : 'Refused: rebuilding sample data is only available when APP_ENV is one of: '
                .implode(', ', $allowed).' (current: '.app()->environment().').';
    }
}
