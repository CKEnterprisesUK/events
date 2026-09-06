<?php

namespace App\Jobs;

use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces the audit-log retention window by deleting rows older than
 * {@see RETENTION_MONTHS} months. (Security — audit retention; data-minimisation)
 *
 * ## Why this job exists
 *
 * The audit trail is append-only and would otherwise grow without bound. A
 * fixed retention window keeps the table bounded and satisfies data-minimisation
 * (we do not keep activity records longer than we need). 24 months balances
 * accounting/dispute needs against not hoarding old data.
 *
 * ## Scheduling
 *
 * Production runs on shared cPanel hosting where `proc_open` is disabled, so the
 * scheduler cannot spawn child processes. Following the same pattern as
 * {@see ReleaseExpiredReservationsJob}, this job is invoked DIRECTLY from a cron
 * entry via an artisan command that runs it synchronously
 * (`php artisan audit:prune`), NOT via `Schedule::command()`. A daily cron is
 * ample — the window moves slowly.
 *
 * ## Safety / idempotency
 *
 * Deletion is batched (delete the oldest N rows repeatedly until none remain
 * older than the cutoff) so a very large backlog never issues one enormous
 * DELETE that could lock the table or exhaust memory. Re-running the job is
 * harmless: once nothing predates the cutoff, it deletes nothing.
 */
class PruneAuditLogsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** How long an audit record is retained before it is pruned. */
    public const RETENTION_MONTHS = 24;

    /** Rows deleted per batch, to avoid one oversized DELETE. */
    private const BATCH_SIZE = 1000;

    /**
     * Delete every audit record older than the retention cutoff, in batches.
     *
     * @return int The number of rows deleted.
     */
    public function handle(): int
    {
        // Harmless safety net so a partial-migration state no-ops rather than
        // errors (mirrors ReleaseExpiredReservationsJob).
        if (! Schema::hasTable('audit_logs')) {
            return 0;
        }

        $cutoff = $this->cutoff();
        $deleted = 0;

        do {
            $batch = AuditLog::query()
                ->where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += AuditLog::query()->whereIn('id', $batch)->delete();
        } while ($batch->count() === self::BATCH_SIZE);

        return $deleted;
    }

    /**
     * The retention cutoff: records with `created_at` strictly before this are
     * pruned.
     */
    public function cutoff(): Carbon
    {
        return now()->subMonths(self::RETENTION_MONTHS);
    }
}
