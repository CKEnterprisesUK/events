<?php

use App\Jobs\PruneAuditLogsJob;
use App\Jobs\ReleaseExpiredReservationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
