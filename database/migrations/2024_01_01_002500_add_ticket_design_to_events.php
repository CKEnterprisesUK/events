<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-Event printed-ticket design: custom instructions and two optional
     * sponsor banner images shown on the downloadable A4 ticket PDF.
     *
     *   - events.ticket_instructions : free-text entry instructions printed on
     *     the ticket (e.g. "Bring this e-ticket with you"). NULL = omit.
     *   - events.sponsor_top_path    : optional landscape sponsor banner shown
     *     across the TOP of the ticket. NULL = none.
     *   - events.sponsor_bottom_path : optional landscape sponsor banner shown
     *     across the BOTTOM of the ticket. Also surfaced on the public
     *     storefront/event page. NULL = none.
     *
     * All nullable so the design is fully optional and the ticket renders with
     * sensible defaults when unset. (Ticket customisation)
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->text('ticket_instructions')->nullable()->after('poster_path');
            $table->string('sponsor_top_path')->nullable()->after('ticket_instructions');
            $table->string('sponsor_bottom_path')->nullable()->after('sponsor_top_path');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['ticket_instructions', 'sponsor_top_path', 'sponsor_bottom_path']);
        });
    }
};
