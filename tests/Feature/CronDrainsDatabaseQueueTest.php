<?php

namespace Tests\Feature;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Task 26.2 — smoke test that the cron-driven queue drain actually processes
 * jobs sitting on the DATABASE queue.
 *
 * On shared cPanel there is no persistent worker. A single per-minute cron runs
 * `php artisan schedule:run`, and the scheduler (routes/console.php, task 26.1)
 * runs `queue:work --stop-when-empty --max-time=50` to drain the DB queue
 * (QUEUE_CONNECTION=database). This test proves that path end to end:
 *
 *   1. dispatch a real ShouldQueue job onto the database queue,
 *   2. assert a row landed in the `jobs` table (so it is genuinely queued, not
 *      run inline),
 *   3. run the drain command exactly as the scheduler does
 *      (`queue:work --stop-when-empty`), and
 *   4. assert the job's side effect happened AND the `jobs` table drained to 0.
 *
 * The queue is intentionally NOT faked — the whole point is to exercise the
 * real database driver the way the cron does in production. No DDL is issued;
 * RefreshDatabase provides the `jobs` table from the real migrations.
 *
 * _Requirements: 15.2, 15.3_
 */
class CronDrainsDatabaseQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_work_stop_when_empty_drains_a_db_queued_job(): void
    {
        // Sanity: the connection under test really is the database driver, the
        // same one the cron drains in production.
        $this->assertSame('database', config('queue.default'));

        $marker = 'cron-drain-smoke-'.uniqid();
        Cache::forget($marker);

        // Dispatch a real queued job. With QUEUE_CONNECTION=database this
        // serialises the job into the `jobs` table rather than running inline.
        RecordsSideEffectJob::dispatch($marker);

        // It must actually be sitting on the DB queue — proving it was queued,
        // not executed synchronously.
        $this->assertSame(
            1,
            DB::table('jobs')->count(),
            'Expected the dispatched job to be persisted on the database queue.'
        );
        $this->assertNull(
            Cache::get($marker),
            'Side effect must not have run yet — the job is still queued.'
        );

        // Drain the queue exactly as the per-minute scheduler does.
        $exitCode = Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $exitCode, 'queue:work should exit cleanly.');

        // The job ran (side effect recorded) and the queue is fully drained.
        $this->assertSame(
            $marker,
            Cache::get($marker),
            'The queued job should have executed during the drain.'
        );
        $this->assertSame(
            0,
            DB::table('jobs')->count(),
            'The database queue should be empty after draining.'
        );
        $this->assertSame(
            0,
            DB::table('failed_jobs')->count(),
            'No job should have failed during the drain.'
        );
    }
}

/**
 * Minimal queued job used only by the smoke test above. It records a side
 * effect (a cache marker) when it runs, so the test can prove the drain
 * actually executed it. Uses the array cache store configured for tests, which
 * persists for the lifetime of the in-process `queue:work` run.
 */
class RecordsSideEffectJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private string $marker) {}

    public function handle(): void
    {
        Cache::forever($this->marker, $this->marker);
    }
}
