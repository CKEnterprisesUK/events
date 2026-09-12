<?php

use App\Jobs\BackfillStripeFeesJob;
use App\Jobs\PruneAuditLogsJob;
use App\Jobs\ReleaseExpiredReservationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep reservations whose 900-second window has elapsed and hand their held
// capacity back to each Ticket_Type. Runs the job SYNCHRONOUSLY (in-process),
// not via the queue, so it needs no long-running worker and — critically — no
// `proc_open`. Shared cPanel hosting disables `proc_open`, which means the
// scheduler's `Schedule::command(...)` (it spawns a child process) cannot run.
// Cron therefore calls this command directly:
//
//   php artisan reservations:release-expired
//
// (Requirements 10.7, 10.12 — Design: ReleaseExpiredReservationsJob)
Artisan::command('reservations:release-expired', function () {
    dispatch_sync(new ReleaseExpiredReservationsJob);
    $this->info('Expired reservations released.');
})->purpose('Release capacity held by reservations whose window has elapsed');

// Prune audit-log rows past the retention window (24 months). Runs the job
// SYNCHRONOUSLY, like reservations:release-expired above, for the same reason:
// shared cPanel hosting disables `proc_open`, so `schedule:run` cannot be used
// and cron must call the command directly. A DAILY cron is ample:
//
//   0 3 * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan audit:prune >> /dev/null 2>&1
//
// (Security — audit retention; Hosting and Deployment Notes)
Artisan::command('audit:prune', function () {
    $deleted = dispatch_sync(new PruneAuditLogsJob);
    $this->info("Pruned {$deleted} audit log row(s) past retention.");
})->purpose('Delete audit-log records older than the retention window');

// Backfill the ACTUAL Stripe processing fee onto paid Orders where it is still
// unknown, by reading each charge's balance transaction from the Company's
// connected account. The fee is captured opportunistically at payment
// confirmation, but Stripe may not have the balance transaction ready at that
// instant; this sweeper fills the gaps once the charge has settled, so every
// paid Order converges on its true fee. Runs SYNCHRONOUSLY, like the sweepers
// above, for the same proc_open reason. A frequent cron keeps the fee latency
// low without a persistent worker:
//
//   */10 * * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan stripe:backfill-fees >> /dev/null 2>&1
//
// (Truthful-payout feature)
Artisan::command('stripe:backfill-fees', function () {
    $captured = dispatch_sync(new BackfillStripeFeesJob);
    $this->info("Captured Stripe fees for {$captured} order(s).");
})->purpose('Backfill actual Stripe processing fees onto paid orders missing them');

// NOTE ON DRAINING THE QUEUE (ticket emails, webhook processing):
// Do NOT use `schedule:run` here. On shared cPanel hosting `proc_open` is
// disabled, so `schedule:run` cannot spawn the `queue:work` child process it
// needs and every scheduled command fails with "The Process class relies on
// proc_open". Instead, point cron DIRECTLY at `queue:work` so jobs run inside
// cron's own PHP process (no child process, no proc_open):
//
//   * * * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan queue:work --stop-when-empty --max-time=50 >> /dev/null 2>&1
//   * * * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan reservations:release-expired >> /dev/null 2>&1
//
// (Requirements 15.2, 15.3 — Design: Hosting and Deployment Notes)

// Seed the pre-prod / staging database with realistic sample data (companies,
// events, orders, and the known test logins). Designed to be run from the
// cPanel Git deploy (no SSH, no terminal) or by hand in a non-prod environment.
//
// HARD GUARDS — this command refuses to do anything dangerous:
//   * Never runs when APP_ENV=production.
//   * Never runs unless the resolved DB connection is really mysql/mariadb —
//     this blocks the sqlite-fallback trap where a wrong/partial .env silently
//     points Laravel at database/database.sqlite instead of the real DB.
//   * By default only seeds when the DB is EMPTY (no companies yet), so
//     repeated deploys do not clobber data. Pass --fresh to wipe and rebuild.
//
// Known logins created by Database\Seeders\PreprodSeeder (password: "password"):
//   super@preprod.test   (Super_Admin)   owner@preprod.test   (company Owner)
//
//   php artisan preprod:seed            # seed only if empty
//   php artisan preprod:seed --fresh    # migrate:fresh + seed (DESTRUCTIVE)
Artisan::command('preprod:seed {--fresh : Wipe the database and rebuild before seeding}', function () {
    if (app()->environment('production')) {
        $this->error('Refusing to seed: APP_ENV is production.');

        return 1;
    }

    $connection = config('database.default');
    $driver = config("database.connections.{$connection}.driver");

    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        $this->error("Refusing to seed: resolved DB connection is '{$connection}' (driver '{$driver}'), not mysql/mariadb.");
        $this->error('Check your .env — a wrong/partial .env falls back to sqlite. NOT proceeding.');

        return 1;
    }

    if ($this->option('fresh')) {
        $this->warn('Wiping the database and rebuilding (migrate:fresh --seed)…');
        $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        $this->info('Pre-prod database wiped, migrated, and seeded.');

        return 0;
    }

    // Non-destructive path: only seed a fresh/empty database so repeated
    // deploys are safe. "Empty" = the companies table has no rows yet.
    $alreadySeeded = Schema::hasTable('companies') && DB::table('companies')->exists();

    if ($alreadySeeded) {
        $this->info('Database already has data — skipping seed (use --fresh to rebuild).');

        return 0;
    }

    $this->call('db:seed', ['--force' => true]);
    $this->info('Pre-prod database seeded. Logins: super@preprod.test / owner@preprod.test (password: password).');

    return 0;
})->purpose('Seed the pre-prod/staging database with sample data (non-production only)');
