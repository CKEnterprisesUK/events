<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\CronRuns;
use App\Services\SystemHealth;
use Illuminate\Contracts\View\View;

/**
 * Super-admin "System health" surface: a read-only view of the infrastructure
 * the Platform depends on (database, queue backlog, failed jobs, cache). Gives
 * a Super_Admin visibility into a stalled queue worker or a broken dependency
 * that would otherwise silently back up ticket emails and Stripe webhook
 * processing.
 *
 * Not tenant-scoped — a Super_Admin operates across the whole Platform. The
 * checks are performed by {@see SystemHealth}, which is defensive (a failing
 * dependency is reported, never thrown) so the page always renders.
 */
class SystemHealthController extends Controller
{
    public function __construct(
        private readonly SystemHealth $health,
        private readonly CronRuns $cron,
    ) {}

    public function index(): View
    {
        $report = $this->health->report();
        $jobs = $this->cron->health();

        // A cron job that is failing, overdue or stuck makes the platform
        // "attention needed". A job that has never run is shown but does not
        // flip the overall status, so a fresh deploy isn't alarming.
        $cronOk = ! collect($jobs)->contains(fn (array $j): bool => in_array($j['status'], ['failed', 'overdue', 'stuck'], true));
        $report['healthy'] = $report['healthy'] && $cronOk;

        return view('admin.system.index', [
            'report' => $report,
            'jobs' => $jobs,
        ]);
    }
}
