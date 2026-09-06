<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add location fields to the `events` table so an Event can declare whether
     * it is held in person or online, and (for in-person events) carry a
     * human-readable address plus geocoded map coordinates:
     *   - location_mode : 'in_person' (default) or 'online'.
     *   - address       : optional free-text street address for in-person events.
     *   - latitude/longitude : optional geocoded coordinates for the map pin.
     *
     * These sit after the existing `venue` column. `poster_path` already exists
     * (added by 2024_01_01_001800_add_poster_to_branding) so it is not touched
     * here. (Requirements 4.1, 4.2)
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('location_mode', 20)->default('in_person')->after('venue');
            $table->text('address')->nullable()->after('location_mode');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['location_mode', 'address', 'latitude', 'longitude']);
        });
    }
};
