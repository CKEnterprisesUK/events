<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `cron_runs` holds ONE row per scheduled command: when it last started and
     * finished, whether it succeeded, how long it took and a one-line summary of
     * what it did. The Super_Admin "System health" page reads it to show whether
     * each cron job is running on time. Written by App\Services\CronRuns.
     */
    public function up(): void
    {
        Schema::create('cron_runs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('last_status', 16)->default('running');
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->unsignedInteger('last_actions')->default(0);
            $table->text('last_summary')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('runs_total')->default(0);
            $table->unsignedBigInteger('failures_total')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_runs');
    }
};
