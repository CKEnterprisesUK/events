<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Introduce a per-ticket-type capacity mode so a type can either carry its
     * own capped ceiling (the existing behaviour) or draw entirely from the
     * Event's overall capacity as a shared pool.
     *
     *   - ticket_types.capacity_mode : 'capped' (own ceiling) or 'shared_pool'
     *     (governed solely by the Event overall capacity). NOT NULL, defaults
     *     to 'capped' so existing rows and inserts keep current semantics.
     *   - ticket_types.capacity      : made NULLABLE so shared-pool types may
     *     omit a per-type ceiling. The column stays a SIGNED integer to match
     *     the existing definition (`$table->integer('capacity')` /
     *     `int(11)`); only nullability changes here.
     *
     * Backfill classifies existing rows: a type whose capacity equalled its
     * Event's non-null overall capacity is treated as a shared pool; every
     * other row stays 'capped'. (Requirements 2.1, 2.2, 2.3, 2.9)
     */
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            // New mode discriminator, defaulting to the existing capped behaviour.
            $table->string('capacity_mode', 20)->default('capped')->after('capacity');
            // Preserve the signed integer type; only relax NOT NULL so
            // shared-pool types can omit a per-type ceiling.
            $table->integer('capacity')->nullable()->change();
        });

        // Classify existing rows: shared_pool iff the type's capacity equalled
        // its Event's non-null overall capacity, otherwise capped.
        DB::statement(<<<'SQL'
            UPDATE ticket_types tt
            JOIN events e ON e.id = tt.event_id
            SET tt.capacity_mode = CASE
                WHEN e.capacity IS NOT NULL AND tt.capacity = e.capacity THEN 'shared_pool'
                ELSE 'capped'
            END
        SQL);
    }

    /**
     * Reverse the migration.
     *
     * Drops the capacity_mode discriminator. `capacity` is intentionally left
     * nullable on rollback: widening NOT NULL -> NULL is safe and reverting it
     * could fail against rows that now legitimately hold NULL.
     */
    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('capacity_mode');
        });
    }
};
