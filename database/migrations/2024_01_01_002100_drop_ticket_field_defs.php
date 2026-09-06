<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the custom ticket-fields feature: the `ticket_field_defs` JSON column
 * on both `companies` and `events`. The feature added confusing, never-valued
 * fields to tickets and has been removed from the product surface, so the
 * columns are dropped. Reversible: `down()` restores the nullable JSON columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'ticket_field_defs')) {
                $table->dropColumn('ticket_field_defs');
            }
        });

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'ticket_field_defs')) {
                $table->dropColumn('ticket_field_defs');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'ticket_field_defs')) {
                $table->json('ticket_field_defs')->nullable();
            }
        });

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'ticket_field_defs')) {
                $table->json('ticket_field_defs')->nullable();
            }
        });
    }
};
