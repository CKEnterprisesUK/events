<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Super_Admin-facing, read-mostly system health checks. Surfaces the state of
 * the infrastructure the Platform depends on — the database, the queue (which
 * drains ticket emails and Stripe webhook processing), failed jobs, and the
 * cache — so an operator can spot a stalled worker or a broken connection
 * before it silently backs up.
 *
 * Every check is defensive: a failure in one dependency is caught and reported
 * as an unhealthy result rather than throwing, so the health page always
 * renders. The only write performed is a single throwaway cache round-trip used
 * to prove the cache store accepts writes; it is deleted immediately.
 *
 * On the default configuration the queue, failed jobs, cache and sessions all
 * live in the database, so a database failure will cascade into those checks —
 * that is intentional and accurate.
 */
class SystemHealth
{
    /**
     * A queue backlog above this many pending jobs is flagged as a warning: the
     * worker may be down or falling behind. Tuned for a small cPanel-cron-drained
     * queue where a healthy backlog is normally near zero.
     */
    private const QUEUE_BACKLOG_WARNING = 25;

    /**
     * Run every check and return a structured report the view renders directly.
     *
     * @return array{
     *     healthy: bool,
     *     checks: array<int, array{key: string, label: string, ok: bool, status: string, detail: string}>
     * }
     */
    public function report(): array
    {
        $checks = [
            $this->databaseCheck(),
            $this->queueCheck(),
            $this->failedJobsCheck(),
            $this->cacheCheck(),
        ];

        $healthy = ! collect($checks)->contains(fn (array $check): bool => $check['ok'] === false);

        return [
            'healthy' => $healthy,
            'checks' => $checks,
        ];
    }

    /**
     * Prove the database answers a trivial query. Everything else on the default
     * config depends on this, so it runs first.
     */
    private function databaseCheck(): array
    {
        try {
            DB::connection()->select('select 1');
            $driver = (string) DB::connection()->getDriverName();

            return $this->result('database', 'Database', true, 'Connected', 'Responding ('.$driver.').');
        } catch (Throwable $e) {
            return $this->result('database', 'Database', false, 'Unreachable', $e->getMessage());
        }
    }

    /**
     * Report the queue backlog (pending jobs waiting to be processed). A large
     * backlog usually means the worker/cron that drains the queue is not running
     * — which stalls ticket emails and Stripe webhook processing.
     */
    private function queueCheck(): array
    {
        $connection = (string) config('queue.default');

        // Only the database queue exposes a countable backlog cheaply. For other
        // drivers, report the driver without a misleading count.
        if ($connection !== 'database') {
            return $this->result('queue', 'Queue backlog', true, 'N/A', 'Queue driver is "'.$connection.'"; backlog is not counted here.');
        }

        try {
            $table = (string) config('queue.connections.database.table', 'jobs');
            $pending = (int) DB::table($table)->count();

            if ($pending > self::QUEUE_BACKLOG_WARNING) {
                return $this->result(
                    'queue',
                    'Queue backlog',
                    false,
                    'Backlog',
                    $pending.' jobs waiting. The queue worker/cron may be down or falling behind.',
                );
            }

            return $this->result('queue', 'Queue backlog', true, 'Healthy', $pending.' jobs waiting.');
        } catch (Throwable $e) {
            return $this->result('queue', 'Queue backlog', false, 'Error', $e->getMessage());
        }
    }

    /**
     * Report failed jobs. Any failed job is worth an operator's attention (a
     * ticket email or webhook that never completed), so a non-zero count is a
     * warning rather than a hard failure.
     */
    private function failedJobsCheck(): array
    {
        try {
            $table = (string) config('queue.failed.table', 'failed_jobs');
            $failed = (int) DB::table($table)->count();

            if ($failed > 0) {
                return $this->result(
                    'failed_jobs',
                    'Failed jobs',
                    false,
                    $failed.' failed',
                    'Review and retry or clear them (php artisan queue:retry / queue:flush).',
                );
            }

            return $this->result('failed_jobs', 'Failed jobs', true, 'None', 'No failed jobs.');
        } catch (Throwable $e) {
            return $this->result('failed_jobs', 'Failed jobs', false, 'Error', $e->getMessage());
        }
    }

    /**
     * Prove the cache store accepts a write and reads the same value back. Uses
     * a unique throwaway key deleted immediately, so it never collides with real
     * cached data.
     */
    private function cacheCheck(): array
    {
        $store = (string) config('cache.default');
        $key = 'health-check:'.Str::random(12);
        $value = (string) now()->timestamp;

        try {
            Cache::put($key, $value, 10);
            $readBack = Cache::get($key);
            Cache::forget($key);

            if ($readBack !== $value) {
                return $this->result('cache', 'Cache', false, 'Inconsistent', 'Wrote to the "'.$store.'" store but read back a different value.');
            }

            return $this->result('cache', 'Cache', true, 'Healthy', 'Read/write round-trip succeeded ("'.$store.'" store).');
        } catch (Throwable $e) {
            return $this->result('cache', 'Cache', false, 'Error', $e->getMessage());
        }
    }

    /**
     * @return array{key: string, label: string, ok: bool, status: string, detail: string}
     */
    private function result(string $key, string $label, bool $ok, string $status, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
