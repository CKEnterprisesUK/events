<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `error_stats` table is a tiny hourly counter of HTTP error responses
     * (403, 404, 500), one row per (hour, status code). It feeds the baseline
     * graph on /admin/errors and the spike detector that alerts Super_Admins
     * when an hour runs well above the usual level.
     *
     * Unlike `error_reports` (one rich row per 500, with trace), this stores
     * counts only, so recording a 404 costs one upsert and no PII.
     *
     *   - bucket_hour : start of the hour (app timezone) the errors fell in.
     *   - status_code : 403, 404 or 500 (any 5xx is counted as 500).
     *   - hits        : how many were served in that hour.
     */
    public function up(): void
    {
        Schema::create('error_stats', function (Blueprint $table) {
            $table->id();
            $table->dateTime('bucket_hour');
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('hits')->default(0);
            $table->timestamps();
            $table->unique(['bucket_hour', 'status_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_stats');
    }
};
