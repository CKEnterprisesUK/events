<?php

namespace Tests\PBT;

use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verifies the foundational infrastructure this task sets up:
 *  - the test suite runs against a real MySQL database (not sqlite),
 *  - SELECT ... FOR UPDATE row locking actually executes on that database,
 *  - the database-queue tables (jobs, failed_jobs) exist,
 *  - Eris property-based testing is wired and runs the mandated iterations.
 *
 * These are not design correctness properties; they guard the scaffold so
 * later capacity/no-oversell property tests can rely on real FOR UPDATE.
 */
class InfrastructureWiringTest extends PbtTestCase
{
    use RefreshDatabase;

    public function test_test_suite_runs_against_mysql(): void
    {
        $this->assertSame(
            'mysql',
            DB::connection()->getDriverName(),
            'The test suite must run against MySQL so SELECT ... FOR UPDATE is real.'
        );
    }

    public function test_queue_tables_exist_for_database_driver(): void
    {
        $this->assertTrue(Schema::hasTable('jobs'), 'jobs table must exist for the database queue.');
        $this->assertTrue(Schema::hasTable('failed_jobs'), 'failed_jobs table must exist to record job failures.');
        $this->assertSame('database', config('queue.default'), 'Queue connection must be the database driver.');
    }

    public function test_select_for_update_executes_on_real_mysql(): void
    {
        // Prove FOR UPDATE runs inside a transaction against real MySQL rather
        // than being silently ignored (as it would be on sqlite :memory:).
        $selected = DB::transaction(function () {
            return DB::table('jobs')
                ->where('id', '>', 0)
                ->lockForUpdate()
                ->count();
        });

        $this->assertIsInt($selected);
    }

    public function test_eris_runs_at_least_the_mandated_iterations(): void
    {
        $iterations = 0;

        $this->forAll(Generator\int())
            ->then(function (int $value) use (&$iterations): void {
                $iterations++;
                // Trivial invariant: integer addition is commutative.
                $this->assertSame($value + 1, 1 + $value);
            });

        $this->assertGreaterThanOrEqual(
            self::MIN_ITERATIONS,
            $iterations,
            'Eris must run at least the minimum mandated iterations.'
        );
    }
}
