<?php

use App\Jobs\AnonymiseExpiredCustomerDataJob;
use App\Jobs\BackfillStripeFeesJob;
use App\Jobs\PruneAuditLogsJob;
use App\Jobs\ReconcilePaidOrdersJob;
use App\Jobs\RefreshStripeAccountStateJob;
use App\Jobs\ReleaseExpiredReservationsJob;
use App\Services\CronRuns;
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
    return CronRuns::track('reservations:release-expired', function () {
        dispatch_sync(new ReleaseExpiredReservationsJob);
        $this->info('Expired reservations released.');

        return 'Swept expired reservations';
    });
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
    return CronRuns::track('audit:prune', function () {
        $deleted = (int) dispatch_sync(new PruneAuditLogsJob);
        $this->info("Pruned {$deleted} audit log row(s) past retention.");

        return ['summary' => "Pruned {$deleted} audit log row(s) past retention", 'actions' => $deleted];
    });
})->purpose('Delete audit-log records older than the retention window');

// Enforce each Company's customer-data retention policy: anonymise the personal
// data of Customers whose LAST order has aged past that Company's retention
// window (default 24 months, chosen during onboarding), keeping the
// transactional records. Runs SYNCHRONOUSLY, like the sweepers above, for the
// same proc_open reason. A DAILY cron is ample — the windows move slowly:
//
//   0 2 * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan customers:anonymise-expired >> /dev/null 2>&1
//
// (Requirement 22.2 — customer-data retention; data-minimisation)
Artisan::command('customers:anonymise-expired', function () {
    // Run the job in-process and read its per-run summary off the same instance
    // (dispatch_sync can serialize the job, which would blank our local copy).
    return CronRuns::track('customers:anonymise-expired', function () {
        $job = new AnonymiseExpiredCustomerDataJob;
        app()->call([$job, 'handle']);
        $s = $job->summary;

        $msg = "Anonymised customer data on {$s['orders_anonymised']} order(s) across "
            ."{$s['companies_swept']} company(ies) past their retention window.";
        $this->info($msg);

        // The job already writes its own per-company GDPR audit rows, so the
        // generic cron audit entry is skipped (audit: false) to avoid duplicates.
        return ['summary' => $msg, 'actions' => $s['orders_anonymised']];
    }, audit: false);
})->purpose('Anonymise customer personal data past each company\'s retention window');

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
    return CronRuns::track('stripe:backfill-fees', function () {
        $captured = (int) dispatch_sync(new BackfillStripeFeesJob);
        $this->info("Captured Stripe fees for {$captured} order(s).");

        return ['summary' => "Captured Stripe fees for {$captured} order(s)", 'actions' => $captured];
    });
})->purpose('Backfill actual Stripe processing fees onto paid orders missing them');

// Reconcile Orders against Stripe to recover any whose payment SUCCEEDED but
// which were never confirmed (the money-in/no-tickets case): for each matching
// Order whose Stripe Checkout Session reports PAID, run the same authoritative
// confirmation the webhook uses — mark paid, un-void tickets, re-commit
// capacity (honouring the seats), and send the ticket email.
//
// Pass an order_reference to recover ONE order (the manual tool for a specific
// customer); omit it to sweep all unconfirmed paid-capable orders (a standing
// safety net in case a webhook is ever missed). Runs SYNCHRONOUSLY, like the
// other sweepers, for the same proc_open reason.
//
//   php artisan orders:reconcile-paid                    # sweep all
//   php artisan orders:reconcile-paid L5NWIX9B5PHP       # one order
//
// A reconciliation cron is a sensible safety net once validated:
//   */15 * * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan orders:reconcile-paid >> /dev/null 2>&1
//
// (Reservation-vs-payment race fix)
Artisan::command('orders:reconcile-paid {order_reference? : Recover a single order by reference; omit to sweep all}', function () {
    $reference = $this->argument('order_reference');

    $body = function () use ($reference) {
        $job = new ReconcilePaidOrdersJob($reference);
        app()->call([$job, 'handle']);
        $s = $job->summary;

        $msg = "Reconcile complete: examined {$s['examined']}, recovered {$s['recovered']}, skipped {$s['skipped']}."
            .($reference !== null ? " (order {$reference})" : '');
        $this->info($msg);

        if ($reference !== null && $s['examined'] === 0) {
            $this->warn("No order found with reference '{$reference}'.");
        }

        return ['summary' => $msg, 'actions' => (int) $s['recovered']];
    };

    // A manual single-order recovery is not the scheduled sweep, so it must not
    // overwrite the cron health record.
    if ($reference !== null) {
        $body();

        return 0;
    }

    return CronRuns::track('orders:reconcile-paid', $body);
})->purpose('Reconcile orders against Stripe and recover any paid-but-unconfirmed order');

// Refresh the connected-account onboarding/verification snapshot for every
// Company that has a connected Stripe account, by re-reading the account from
// Stripe. This backfills the requirements/disabled-reason detail for EXISTING
// customers who connected before this feature existed (and never triggered an
// onboarding return or an account.updated webhook), so an already-blocked
// customer sees exactly what Stripe is waiting on. Runs SYNCHRONOUSLY, like the
// other sweepers, for the same proc_open reason.
//
// Run once by hand right after deploy to refresh existing accounts, then keep a
// daily cron as a reconciliation pass in case a webhook is ever missed:
//
//   0 4 * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan stripe:refresh-accounts >> /dev/null 2>&1
//
// (Requirements 11.3, 11.4)
Artisan::command('stripe:refresh-accounts', function () {
    // Run the job in-process and read its per-run summary directly off the same
    // instance. We call handle() through the container (not the bus) so the very
    // instance we hold is the one that ran — dispatch_sync can hand the job
    // through serialization, which would leave our local $job->summary empty.
    return CronRuns::track('stripe:refresh-accounts', function () {
        $job = new RefreshStripeAccountStateJob;
        app()->call([$job, 'handle']);
        $s = $job->summary;

        $this->info("Refreshed Stripe account state for {$s['refreshed']} company(ies).");
        $this->line("Database: connection '{$s['connection']}', database '{$s['database']}', total companies {$s['total_companies']}.");
        $this->line(
            "Connected accounts found: {$s['matched']} "
            ."(refreshed {$s['refreshed']}, blank id {$s['skipped_blank']}, failed {$s['failed']})."
        );

        if ($s['total_companies'] === 0) {
            $this->warn('This database has NO companies at all. The app is almost certainly connected to the wrong database — check the .env DB_* settings (a partial .env falls back to sqlite). Compare the connection/database above with the one you inspect in phpMyAdmin.');
        } elseif ($s['matched'] === 0) {
            $this->warn('Companies exist but none has a Stripe account id stored. If a company shows as "connected" in the UI, its onboarding may have started without the account id being saved.');
        } elseif ($s['failed'] > 0) {
            $this->warn('One or more accounts could not be read from Stripe — see the application log (storage/logs) for the reason.');
        }

        return [
            'summary' => "Refreshed {$s['refreshed']} of {$s['matched']} connected account(s); {$s['failed']} failed.",
            'actions' => (int) $s['refreshed'],
        ];
    });
})->purpose('Re-read connected Stripe accounts and refresh their verification/requirements state');

// Compare the current hour's 403/404/500 counts with the last 14 days and email
// every Super_Admin if any is well above its usual level (see App\Services\
// ErrorStats). Runs SYNCHRONOUSLY, like the sweepers above (no proc_open). Run
// it every 10-15 minutes by cron:
//
//   */15 * * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan errors:check-spikes >> /dev/null 2>&1
Artisan::command('errors:check-spikes', function () {
    return CronRuns::track('errors:check-spikes', function () {
        $alerted = app(\App\Services\ErrorStats::class)->alertOnSpikes();
        if ($alerted === []) {
            $this->info('No error spikes above baseline.');

            return 'No error spikes above baseline';
        }
        $parts = [];
        foreach ($alerted as $s) {
            $this->warn("HTTP {$s['status']}: {$s['current']} this hour (usual {$s['mean']}, alert level {$s['threshold']}).");
            $parts[] = "HTTP {$s['status']} ({$s['current']} vs usual {$s['mean']})";
        }
        $this->info('Super_Admins alerted.');

        return ['summary' => 'Alerted Super_Admins: '.implode(', ', $parts), 'actions' => count($alerted)];
    });
})->purpose('Email Super_Admins when 403/404/500 errors exceed their baseline');
// Delete captured error reports older than 90 days (they hold URLs, IPs, user
// agents and traces) and hourly error counts older than 400 days. Synchronous,
// like the other sweepers. A DAILY cron is ample:
//
//   30 3 * * * cd /home/<user>/<app> && /opt/cpanel/ea-php85/root/usr/bin/php \
//       artisan errors:prune >> /dev/null 2>&1
Artisan::command('errors:prune', function () {
    return CronRuns::track('errors:prune', function () {
        $d = app(\App\Services\ErrorStats::class)->prune();
        $msg = "Pruned {$d['reports']} error report(s) and {$d['stats']} hourly count row(s) past retention.";
        $this->info($msg);

        return ['summary' => $msg, 'actions' => $d['reports'] + $d['stats']];
    });
})->purpose('Delete error reports and error counts past their retention windows');
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
