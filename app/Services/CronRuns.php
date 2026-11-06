<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Records every run of the scheduled (cron) commands in `cron_runs` and turns
 * that into a health status for the Super_Admin System health page.
 *
 * Commands wrap their body in {@see track()}, which stamps start/finish, catches
 * and records a failure, and writes a system entry to the audit trail when the
 * run did something (or failed). Idle runs of frequent jobs are NOT audited, so
 * a once-a-minute job does not flood the audit table; they only update the
 * single `cron_runs` row.
 *
 * Nothing here may break a command: every database touch is wrapped, so a
 * missing table (migration not applied yet) just means no history is kept.
 */
class CronRuns
{
    /**
     * The jobs we expect to see, with how often cron runs them (minutes). A job
     * is "overdue" once it has not started for twice its interval plus a grace.
     *
     * @var array<string, array{label: string, interval: int, description: string}>
     */
    public const JOBS = [
        'queue:work' => ['label' => 'Queue worker', 'interval' => 1, 'description' => 'Sends ticket emails and processes Stripe webhooks'],
        'reservations:release-expired' => ['label' => 'Release expired reservations', 'interval' => 1, 'description' => 'Hands back held tickets when the 15 minute window lapses'],
        'stripe:backfill-fees' => ['label' => 'Backfill Stripe fees', 'interval' => 10, 'description' => 'Captures actual Stripe fees onto paid orders'],
        'orders:reconcile-paid' => ['label' => 'Reconcile paid orders', 'interval' => 15, 'description' => 'Recovers orders paid in Stripe but not confirmed'],
        'errors:check-spikes' => ['label' => 'Error spike check', 'interval' => 15, 'description' => 'Emails Super_Admins when 403/404/500 errors spike'],
        'customers:anonymise-expired' => ['label' => 'Anonymise expired customers', 'interval' => 1440, 'description' => 'Scrubs customer personal data past each company\'s retention window'],
        'audit:prune' => ['label' => 'Prune audit trail', 'interval' => 1440, 'description' => 'Deletes audit records older than 24 months'],
        'errors:prune' => ['label' => 'Prune error reports', 'interval' => 1440, 'description' => 'Deletes error reports and counts past retention'],
        'stripe:refresh-accounts' => ['label' => 'Refresh Stripe accounts', 'interval' => 1440, 'description' => 'Re-reads connected account verification status'],
    ];

    /** Minutes added to the overdue threshold so a slow cron tick is not flagged. */
    private const GRACE_MINUTES = 5;

    /** A run still "running" after this long is reported as stuck. */
    private const STUCK_MINUTES = 30;

    /**
     * Run a command body, recording the outcome. The callback returns either a
     * summary string or ['summary' => string, 'actions' => int]; "actions" is
     * how many things it changed (0 = nothing to do).
     *
     * @param  callable(): (string|array{summary: string, actions?: int})  $body
     * @param  bool  $audit  write a system audit entry when actions > 0 (set false
     *                       when the job already writes its own audit rows).
     * @return int exit code: 0 on success, 1 on failure (exception is reported, not rethrown)
     */
    public static function track(string $name, callable $body, bool $audit = true): int
    {
        $startedAt = microtime(true);
        self::markStarted($name);

        try {
            $result = $body();
            $summary = is_array($result) ? (string) ($result['summary'] ?? '') : (string) $result;
            $actions = is_array($result) ? (int) ($result['actions'] ?? 0) : 0;
        } catch (Throwable $e) {
            report($e);
            $ms = (int) round((microtime(true) - $startedAt) * 1000);
            self::markFinished($name, false, $ms, 'Failed: '.$e->getMessage(), 0, $e::class.': '.$e->getMessage());
            self::audit($name, AuditLog::CRON_RUN_FAILED, 'Cron job failed: '.$e->getMessage(), ['error' => $e::class]);

            return 1;
        }

        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        self::markFinished($name, true, $ms, $summary, $actions, null);

        if ($audit && $actions > 0) {
            self::audit($name, AuditLog::CRON_RUN_COMPLETED, $summary, ['actions' => $actions, 'duration_ms' => $ms]);
        }

        return 0;
    }

    /**
     * Heartbeat for framework commands we do not wrap (queue:work).
     */
    public static function started(string $name): void
    {
        self::markStarted($name);
    }

    public static function finished(string $name, int $exitCode): void
    {
        self::markFinished($name, $exitCode === 0, 0, $exitCode === 0 ? 'Completed' : 'Exited with code '.$exitCode, 0, $exitCode === 0 ? null : 'Exit code '.$exitCode);
    }

    private static function available(): bool
    {
        try {
            return Schema::hasTable('cron_runs');
        } catch (Throwable) {
            return false;
        }
    }

    private static function markStarted(string $name): void
    {
        try {
            if (! self::available()) {
                return;
            }
            $now = now();
            DB::table('cron_runs')->upsert(
                [['name' => $name, 'last_started_at' => $now, 'last_status' => 'running', 'created_at' => $now, 'updated_at' => $now]],
                ['name'],
                ['last_started_at' => $now, 'last_status' => 'running', 'updated_at' => $now],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function markFinished(string $name, bool $ok, int $ms, string $summary, int $actions, ?string $error): void
    {
        try {
            if (! self::available()) {
                return;
            }
            $now = now();
            $values = [
                'last_finished_at' => $now,
                'last_status' => $ok ? 'ok' : 'failed',
                'last_duration_ms' => $ms,
                'last_actions' => $actions,
                'last_summary' => mb_substr($summary, 0, 1000),
                'last_error' => $error !== null ? mb_substr($error, 0, 2000) : null,
                'runs_total' => DB::raw('runs_total + 1'),
                'failures_total' => $ok ? DB::raw('failures_total') : DB::raw('failures_total + 1'),
                'updated_at' => $now,
            ];
            if ($ok) {
                $values['last_success_at'] = $now;
            }

            $updated = DB::table('cron_runs')->where('name', $name)->update($values);
            if ($updated === 0) {
                DB::table('cron_runs')->insert([
                    'name' => $name, 'last_started_at' => $now, 'created_at' => $now,
                    'runs_total' => 1, 'failures_total' => $ok ? 0 : 1,
                ] + collect($values)->except(['runs_total', 'failures_total'])->all());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function audit(string $name, string $action, string $summary, array $context): void
    {
        try {
            app(AuditLogger::class)->recordSystem(
                action: $action,
                summary: '['.$name.'] '.mb_substr($summary, 0, 300),
                context: ['job' => $name] + $context,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Health of every expected job, in display order.
     *
     * Status is one of: ok, failed, overdue, stuck, never.
     *
     * @return list<array{name: string, label: string, description: string, status: string, ok: bool, detail: string, last_started_at: ?Carbon, last_finished_at: ?Carbon, last_success_at: ?Carbon, duration_ms: ?int, summary: ?string, error: ?string, runs: int, failures: int}>
     */
    public function health(?Carbon $now = null): array
    {
        $now = $now ?? now();
        $rows = [];
        try {
            if (self::available()) {
                $rows = DB::table('cron_runs')->get()->keyBy('name')->all();
            }
        } catch (Throwable $e) {
            report($e);
        }

        $out = [];
        foreach (self::JOBS as $name => $job) {
            $row = $rows[$name] ?? null;
            $started = $row?->last_started_at ? Carbon::parse($row->last_started_at) : null;
            $finished = $row?->last_finished_at ? Carbon::parse($row->last_finished_at) : null;
            $success = $row?->last_success_at ? Carbon::parse($row->last_success_at) : null;

            if ($started === null) {
                $status = 'never';
                $detail = 'Has not run yet. Check the cron entry is in cPanel.';
            } elseif ($row->last_status === 'running' && $started->diffInMinutes($now) > self::STUCK_MINUTES) {
                $status = 'stuck';
                $detail = 'Started '.$started->diffForHumans().' and never finished.';
            } elseif ($started->diffInMinutes($now) > $job['interval'] * 2 + self::GRACE_MINUTES) {
                $status = 'overdue';
                $detail = 'Last ran '.$started->diffForHumans().'. Expected about every '.self::everyLabel($job['interval']).'.';
            } elseif ($row->last_status === 'failed') {
                $status = 'failed';
                $detail = 'Last run failed '.($finished?->diffForHumans() ?? '').'.';
            } else {
                $status = 'ok';
                $detail = 'Last ran '.$started->diffForHumans().'.';
            }

            $out[] = [
                'name' => $name,
                'label' => $job['label'],
                'description' => $job['description'],
                'status' => $status,
                'ok' => $status === 'ok',
                'detail' => $detail,
                'last_started_at' => $started,
                'last_finished_at' => $finished,
                'last_success_at' => $success,
                'duration_ms' => $row?->last_duration_ms !== null ? (int) $row->last_duration_ms : null,
                'summary' => $row?->last_summary,
                'error' => $row?->last_error,
                'runs' => (int) ($row->runs_total ?? 0),
                'failures' => (int) ($row->failures_total ?? 0),
            ];
        }

        return $out;
    }

    private static function everyLabel(int $minutes): string
    {
        return $minutes >= 1440 ? 'day' : ($minutes === 1 ? 'minute' : $minutes.' minutes');
    }
}
