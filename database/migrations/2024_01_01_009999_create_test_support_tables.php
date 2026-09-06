<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TESTING-ONLY support tables.
 *
 * Several tests previously stood up their own helper tables inside
 * setUp()/tearDown() with Schema::create()/Schema::dropIfExists(). On MySQL,
 * DDL triggers an IMPLICIT COMMIT, which breaks the RefreshDatabase wrapping
 * transaction and corrupts DB state for tests that run later in the same
 * `php artisan test` run (intermittent "Base table or view not found" /
 * "table already exists" errors). Migrations run ONCE up-front, outside the
 * per-test transaction, so providing these tables here keeps RefreshDatabase
 * intact and removes all runtime DDL from the suite.
 *
 * This migration is guarded to the `testing` environment and must never be
 * applied to a non-testing database.
 *
 * Tables provided:
 *   - `widgets`          — TenantResolutionTest, UnresolvedTenantTest
 *   - `isolated_records` — TenantIsolationTest
 *
 * The `orders`/`tickets` stub fixture that once lived here (a minimal mirror of
 * the columns the ReleaseExpiredReservationsJob sweep reads) has been removed:
 * task 12.2 created the REAL `orders`/`tickets` schema via their own migrations,
 * so this file must no longer create those tables or it would collide with the
 * real ones. Only the tenant helper tables remain.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Testing-only: never create these tables outside the test database.
        if (! app()->environment('testing')) {
            return;
        }

        // Company-owned helper table for the tenant-isolation / resolution
        // tests. Matches the inline Widget model (widgets: company_id, label).
        Schema::create('widgets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('label');
            $table->timestamps();
        });

        // Company-owned helper table for the tenant-isolation property test.
        // Matches the inline IsolatedRecord model.
        Schema::create('isolated_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::dropIfExists('isolated_records');
        Schema::dropIfExists('widgets');
    }
};
