<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Non-production database operations, run from the browser (no SSH/terminal).
 *
 * On this shared cPanel host there is no terminal and `proc_open`/`shell_exec`
 * are disabled, so schema/seed work cannot be driven from a shell. cPanel Git
 * "Deploy" can run artisan, but its deploy step keeps dirtying the checkout and
 * blocking the next deploy. These actions instead run migrate/seed IN-PROCESS
 * from an authenticated Super_Admin request — the same PHP process that already
 * serves the app — so nothing shells out and the git checkout is never touched.
 *
 * Workflow (pre-prod):
 *   1. git push
 *   2. cPanel Git → "Update from Remote"  (pulls code; runs NO deploy tasks, so
 *      it cannot dirty the working tree)
 *   3. Visit /admin/ops and click "Run migrations" / "Rebuild sample data".
 *
 * GUARDS
 *   * Route group already requires auth + super.admin (Super_Admin only).
 *   * Every action here ALSO refuses when APP_ENV=production, so it can never
 *     mutate the production database even if the routes somehow exist there.
 *   * `reseed` delegates to the `preprod:seed` command, which additionally
 *     refuses unless the resolved DB is really mysql/mariadb (blocks the
 *     sqlite-fallback trap) — see routes/console.php.
 */
class OpsController extends Controller
{
    /**
     * Status page: current migration state + row counts, with the action
     * buttons. Read-only.
     */
    public function index(): \Illuminate\View\View
    {
        $abort = $this->productionGuardMessage();

        $pendingMigrations = [];
        $counts = [];

        if ($abort === null) {
            // Capture `migrate:status` output for display.
            Artisan::call('migrate:status');
            $status = Artisan::output();

            foreach (['companies', 'users', 'events', 'orders', 'tickets'] as $table) {
                $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;
            }

            $pendingMigrations = $status;
        }

        return view('admin.ops.index', [
            'productionBlocked' => $abort,
            'connection' => config('database.default'),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'migrationStatus' => $pendingMigrations,
            'counts' => $counts,
        ]);
    }

    /**
     * Run pending migrations (php artisan migrate --force), in-process.
     */
    public function migrate(): RedirectResponse
    {
        if ($msg = $this->productionGuardMessage()) {
            return back()->with('ops_error', $msg);
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            return back()->with('ops_error', 'Migrate failed: '.$e->getMessage());
        }

        return back()->with('ops_status', trim(Artisan::output()) ?: 'Migrations run.');
    }

    /**
     * Rebuild the sample data. Delegates to the guarded `preprod:seed` command.
     *
     * With `fresh=1` it wipes and rebuilds (migrate:fresh --seed); otherwise it
     * seeds only when the database is empty.
     */
    public function reseed(Request $request): RedirectResponse
    {
        if ($msg = $this->productionGuardMessage()) {
            return back()->with('ops_error', $msg);
        }

        $fresh = $request->boolean('fresh');

        try {
            Artisan::call('preprod:seed', $fresh ? ['--fresh' => true] : []);
        } catch (\Throwable $e) {
            return back()->with('ops_error', 'Reseed failed: '.$e->getMessage());
        }

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
     * Clearing caches is NON-DESTRUCTIVE (it never touches data or schema), so —
     * unlike migrate/reseed — it is intentionally NOT gated behind the
     * non-production guard and is safe to run in any environment. After
     * clearing, routes/config/views are resolved from source on each request
     * (correct, marginally slower) until something re-caches them.
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
     * Returns a refusal message unless we are in a known-safe non-production
     * environment, or null when the operations are allowed.
     *
     * This is an ALLOW-LIST, not a deny-list: ops are permitted ONLY when
     * APP_ENV is one of the explicit pre-prod/dev names below. Anything else —
     * including `production` OR a missing/misconfigured APP_ENV — fails SAFE and
     * refuses. The guard is based on APP_ENV (the app's environment), never the
     * domain name: the pre-prod host must set APP_ENV=staging (see PREPROD.md),
     * while the production host keeps APP_ENV=production.
     */
    private function productionGuardMessage(): ?string
    {
        $allowed = ['local', 'staging', 'preprod', 'development'];

        return app()->environment($allowed)
            ? null
            : 'Refused: pre-prod operations are only available when APP_ENV is one of: '
                .implode(', ', $allowed).' (current: '.app()->environment().').';
    }
}
