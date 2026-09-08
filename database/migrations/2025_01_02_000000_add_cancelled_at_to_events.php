<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a nullable `cancelled_at` timestamp to the `events` table.
     *
     * An Event that has taken confirmed bookings can no longer be deleted (its
     * booking records must survive so customers can be contacted and refunds
     * arranged). Instead the organiser cancels it: `cancelled_at` is stamped
     * and the Event is unpublished, dropping it from the public storefront
     * while retaining every Order. NULL = the Event is live/active as before.
     * (Deletion vs. cancellation rule.)
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('is_published');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};
