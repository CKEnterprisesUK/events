<?php

use App\Jobs\ReleaseExpiredReservationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drain the database queue in short bursts. Shared cPanel hosting has no
// long-running worker, so the single per-minute cron (`php artisan
// schedule:run`) is the only thing that fires — it invokes `queue:work
// --stop-when-empty`, which processes every pending job (ticket emails, webhook
// processing, capacity release) and then exits rather than daemonising.
// `--max-time` caps a burst so a flood of jobs never runs past the next tick,
// and `withoutOverlapping` prevents two overlapping bursts from racing on the
// same rows. (Requirements 15.2, 15.3 — Design: Hosting and Deployment Notes)
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Sweep reservations whose 900-second window has elapsed and hand their held
// capacity back to each Ticket_Type. Runs every minute alongside the per-minute
// `schedule:run` that drains the DB queue, matching the cron-drained-queue
// hosting model (no persistent worker). Overlap is guarded so a slow run never
// double-releases. (Requirements 10.7, 10.12 — Design: ReleaseExpiredReservationsJob)
Schedule::job(new ReleaseExpiredReservationsJob)
    ->everyMinute()
    ->withoutOverlapping();
