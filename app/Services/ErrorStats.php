<?php

namespace App\Services;

use App\Mail\ErrorSpikeAlertMail;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Hourly counts of 403 / 404 / 500 responses, the baseline built from them, and
 * the spike detector that alerts Super_Admins.
 *
 * Recording is one upsert on `error_stats` and never throws. A spike is an
 * hour whose count reaches max(mean + 3 sd, 2 x mean, MIN_HITS) of the same
 * status code over the previous BASELINE_DAYS days (empty hours count as zero).
 */
class ErrorStats
{
    /** Status codes tracked. Any 5xx is folded into 500. */
    public const TRACKED = [403, 404, 500];

    public const BASELINE_DAYS = 14;

    /** Below this many hits in an hour we never alert, however quiet the baseline. */
    public const MIN_HITS = 10;

    /** Standard deviations above the mean that count as abnormal. */
    public const SIGMA = 3.0;

    /** Minimum hours of history before alerting (avoids alerts on a new install). */
    public const MIN_HISTORY_HOURS = 24;

    /** Hours to stay quiet about the same status code after an alert. */
    public const COOLDOWN_HOURS = 6;

    /**
     * Map an exception to the tracked status code it will produce, or null if
     * it is not an error we track (validation, auth redirects, 419, ...).
     */
    public static function statusFor(Throwable $e): ?int
    {
        if ($e instanceof HttpExceptionInterface) {
            $code = $e->getStatusCode();
        } elseif ($e instanceof ModelNotFoundException) {
            $code = 404;
        } elseif ($e instanceof AuthorizationException) {
            $code = 403;
        } elseif ($e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof TokenMismatchException
            || $e instanceof HttpResponseException) {
            // Expected control-flow exceptions (422 / login redirect / 419 /
            // prepared response), not server faults.
            return null;
        } else {
            $code = 500;
        }

        if ($code >= 500) {
            return 500;
        }

        return in_array($code, self::TRACKED, true) ? $code : null;
    }

    /** Days a captured error report (URL, IP, user agent, trace) is kept. */
    public const REPORT_RETENTION_DAYS = 90;

    /** Days the hourly count rows are kept (counts only, no PII). */
    public const STATS_RETENTION_DAYS = 400;

    /** Rows deleted per statement, to avoid one oversized DELETE. */
    private const PRUNE_BATCH = 1000;

    /**
     * Delete error reports and hourly counts past their retention windows.
     *
     * @return array{reports: int, stats: int}
     */
    public function prune(): array
    {
        return [
            'reports' => $this->pruneTable('error_reports', 'created_at', now()->subDays(self::REPORT_RETENTION_DAYS)),
            'stats' => $this->pruneTable('error_stats', 'bucket_hour', now()->subDays(self::STATS_RETENTION_DAYS)),
        ];
    }

    private function pruneTable(string $table, string $column, Carbon $cutoff): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $deleted = 0;
        do {
            $ids = DB::table($table)->where($column, '<', $cutoff->format('Y-m-d H:i:s'))
                ->orderBy('id')->limit(self::PRUNE_BATCH)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        } while ($ids->count() === self::PRUNE_BATCH);

        return $deleted;
    }

    /**
     * Count one error response in the current hour. Never throws.
     */
    public function record(int $status, ?Carbon $at = null): void
    {
        try {
            $bucket = ($at ?? now())->copy()->startOfHour();

            DB::table('error_stats')->upsert(
                [[
                    'bucket_hour' => $bucket->format('Y-m-d H:i:s'),
                    'status_code' => $status,
                    'hits' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                ['bucket_hour', 'status_code'],
                ['hits' => DB::raw('hits + 1'), 'updated_at' => now()],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Daily totals per tracked status for the last $days days (today included),
     * zero-filled, plus the average of the days before today as a baseline.
     *
     * @return array{days: list<string>, series: array<int, list<int>>, baseline: array<int, float>}
     */
    public function daily(int $days = 30): array
    {
        $start = now()->startOfDay()->subDays($days - 1);

        $rows = DB::table('error_stats')
            ->where('bucket_hour', '>=', $start->format('Y-m-d H:i:s'))
            ->get(['bucket_hour', 'status_code', 'hits']);

        $labels = [];
        $series = [];
        foreach (self::TRACKED as $code) {
            $series[$code] = [];
        }
        $index = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $start->copy()->addDays($i)->toDateString();
            $labels[] = $d;
            $index[$d] = $i;
            foreach (self::TRACKED as $code) {
                $series[$code][$i] = 0;
            }
        }

        foreach ($rows as $row) {
            $d = substr((string) $row->bucket_hour, 0, 10);
            if (isset($index[$d], $series[(int) $row->status_code])) {
                $series[(int) $row->status_code][$index[$d]] += (int) $row->hits;
            }
        }

        $baseline = [];
        foreach (self::TRACKED as $code) {
            $prior = array_slice($series[$code], 0, -1);
            $baseline[$code] = $prior === [] ? 0.0 : array_sum($prior) / count($prior);
        }

        return ['days' => $labels, 'series' => $series, 'baseline' => $baseline];
    }

    /**
     * Compare the current hour with the baseline for each tracked status.
     *
     * @return list<array{status: int, current: int, mean: float, threshold: float}>
     */
    public function detectSpikes(?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $hour = $now->copy()->startOfHour();
        $from = $hour->copy()->subDays(self::BASELINE_DAYS);

        $firstBucket = DB::table('error_stats')->min('bucket_hour');
        if ($firstBucket === null) {
            return [];
        }

        $windowStart = Carbon::parse($firstBucket)->greaterThan($from) ? Carbon::parse($firstBucket) : $from;
        $hours = (int) $windowStart->diffInHours($hour);
        if ($hours < self::MIN_HISTORY_HOURS) {
            return [];
        }

        $spikes = [];
        foreach (self::TRACKED as $code) {
            $current = (int) DB::table('error_stats')
                ->where('status_code', $code)
                ->where('bucket_hour', $hour->format('Y-m-d H:i:s'))
                ->sum('hits');

            $hist = DB::table('error_stats')
                ->where('status_code', $code)
                ->where('bucket_hour', '>=', $windowStart->format('Y-m-d H:i:s'))
                ->where('bucket_hour', '<', $hour->format('Y-m-d H:i:s'))
                ->pluck('hits');

            $sum = (float) $hist->sum();
            $sumSq = (float) $hist->sum(fn ($h) => (float) $h * (float) $h);
            $mean = $sum / $hours;
            $variance = max(0.0, ($sumSq / $hours) - ($mean * $mean));
            $threshold = max($mean + self::SIGMA * sqrt($variance), 2 * $mean, (float) self::MIN_HITS);

            if ($current >= $threshold) {
                $spikes[] = [
                    'status' => $code,
                    'current' => $current,
                    'mean' => round($mean, 2),
                    'threshold' => round($threshold, 1),
                ];
            }
        }

        return $spikes;
    }

    /**
     * Detect spikes and email every Super_Admin, once per status per cooldown.
     *
     * @return list<array{status: int, current: int, mean: float, threshold: float}> the spikes alerted on
     */
    public function alertOnSpikes(): array
    {
        $alerted = [];

        foreach ($this->detectSpikes() as $spike) {
            $key = 'error-spike-alert:'.$spike['status'];
            if (! Cache::add($key, now()->toIso8601String(), now()->addHours(self::COOLDOWN_HOURS))) {
                continue;
            }
            $alerted[] = $spike;
        }

        if ($alerted === []) {
            return [];
        }

        $recipients = User::query()
            ->where('is_super_admin', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        foreach ($recipients as $email) {
            try {
                Mail::to($email)->send(new ErrorSpikeAlertMail($alerted));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $alerted;
    }
}
