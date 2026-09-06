<?php

use App\Jobs\ReleaseExpiredReservationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sweep reservations whose 900-second window has elapsed and hand their held
// capacity back to each Ticket_Type. Runs every minute alongside the per-minute
// `schedule:run` that drains the DB queue, matching the cron-drained-queue
// hosting model (no persistent worker). Overlap is guarded so a slow run never
// double-releases. (Requirements 10.7, 10.12 — Design: ReleaseExpiredReservationsJob)
Schedule::job(new ReleaseExpiredReservationsJob)
    ->everyMinute()
    ->withoutOverlapping();
