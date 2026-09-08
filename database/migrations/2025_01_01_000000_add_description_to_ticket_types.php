<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add an optional free-text description to a ticket type so the redesigned
     * authoring accordion can capture and persist a short blurb per type.
     *
     *   - ticket_types.description : nullable TEXT placed after `name`. NULL =
     *     no description (the ticket type has none). Existing rows adopt NULL,
     *     so behaviour is unchanged until an organiser enters a description.
     *
     * The `unlimited` availability option added alongside this feature needs no
     * schema change: `capacity_mode` is already a `varchar(20)` (see
     * 2024_01_01_001900_add_capacity_mode_to_ticket_types) and simply accepts
     * the new value, which is validated in application code via
     * `Rule::in(TicketType::MODES)`. (Requirements 9.1, 9.2, 9.3, 9.5)
     */
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migration by dropping the description column.
     */
    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
