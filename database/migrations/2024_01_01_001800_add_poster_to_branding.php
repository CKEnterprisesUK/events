<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a poster/hero image column to both branding surfaces:
     *   - companies.poster_path : an optional storefront hero image shown at the
     *     top of the Company Storefront.
     *   - events.poster_path    : an optional per-Event poster/hero image shown
     *     on the public Event page (and as a thumbnail on the Storefront
     *     listing), overriding the Company hero where set.
     *
     * These sit alongside the existing `logo_path` columns so an organiser can
     * present both a logo (mark) and a poster (imagery) independently.
     * Nullable so both are optional and inherit/omit cleanly. (Requirement 7.x)
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('poster_path')->nullable()->after('logo_path');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('poster_path')->nullable()->after('logo_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }
};
