<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
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
    public function __construct(private readonly SystemHealth $health) {}

    public function index(): View
    {
        return view('admin.system.index', [
            'report' => $this->health->report(),
        ]);
    }
}
