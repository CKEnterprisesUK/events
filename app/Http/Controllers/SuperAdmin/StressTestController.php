<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Services\StressTestFixture;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Browser-driven door-load stress test for the scan + live-dashboard endpoints,
 * for a host with no SSH/terminal.
 *
 * The page seeds a synthetic fixture (10 scanners, one live event, thousands of
 * scannable Orders), hands the browser a set of device-bound scanner tokens and
 * scannable QR payloads, and a client-side runner then drives `POST /api/scan`
 * and `GET /api/dashboard/live` at a configurable rate while reporting latency
 * and error rates. See {@see StressTestFixture} for the fixture itself.
 *
 * GUARDS
 *   * The route group already requires auth + super.admin (Super_Admin only).
 *   * The routes are registered ONLY in non-production environments, and the
 *     fixture re-checks the APP_ENV allow-list on every mutating call, so the
 *     tool can never seed or mint tokens against the production database even
 *     if a route were reached.
 */
class StressTestController extends Controller
{
    public function __construct(private readonly StressTestFixture $fixture) {}

    /**
     * The stress-test page: fixture status, seed/reset/teardown controls and
     * the in-browser runner.
     */
    public function index(): View
    {
        return view('admin.stress-test.index', [
            'status' => $this->fixture->status(),
            'allowed' => StressTestFixture::isAllowedHere(),
            'environment' => app()->environment(),
            'database' => config('database.connections.'.config('database.default').'.database'),
            'defaultOrders' => StressTestFixture::DEFAULT_ORDERS,
            'minOrders' => StressTestFixture::MIN_ORDERS,
            'maxOrders' => StressTestFixture::MAX_ORDERS,
            'scannerLimitPerMinute' => (int) config('api.throttle.scan'),
        ]);
    }

    /**
     * (Re)build the fixture with the requested number of Orders.
     */
    public function seed(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'orders' => ['required', 'integer', 'between:'.StressTestFixture::MIN_ORDERS.','.StressTestFixture::MAX_ORDERS],
        ]);

        try {
            $summary = $this->fixture->seed((int) $data['orders']);
        } catch (Throwable $e) {
            return back()->with('ops_error', 'Stress-test seed failed: '.$e->getMessage());
        }

        return back()->with('ops_status', sprintf(
            'Seeded %d scanners, 1 live event, %d orders and %d tickets.',
            $summary['scanners'],
            $summary['orders'],
            $summary['tickets'],
        ));
    }

    /**
     * Mint fresh scanner tokens plus the unscanned QR payloads for the runner.
     * POST (it creates credentials) and never cached.
     */
    public function session(): JsonResponse
    {
        try {
            $session = $this->fixture->issueSession();
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($session)->header('Cache-Control', 'no-store');
    }

    /**
     * Clear check-ins so the next stage can re-scan the same Orders.
     */
    public function resetScans(): RedirectResponse
    {
        try {
            $reset = $this->fixture->resetScans();
        } catch (Throwable $e) {
            return back()->with('ops_error', 'Reset failed: '.$e->getMessage());
        }

        return back()->with('ops_status', "Reset {$reset} checked-in order(s) and cleared the scan feed.");
    }

    /**
     * Remove the fixture and its scanner tokens.
     */
    public function teardown(): RedirectResponse
    {
        try {
            $summary = $this->fixture->teardown();
        } catch (Throwable $e) {
            return back()->with('ops_error', 'Teardown failed: '.$e->getMessage());
        }

        return back()->with('ops_status', $summary);
    }
}
