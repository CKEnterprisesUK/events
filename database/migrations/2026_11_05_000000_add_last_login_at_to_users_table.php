<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the last time a user actually signed in, distinct from
     * `last_activity_at` (which the SessionTimeout middleware overwrites on
     * every authenticated request and so only reflects the most recent
     * activity, not the sign-in itself).
     *
     *   - users.last_login_at : timestamp of the most recent successful sign-in,
     *     NULL for a user who has never completed a login.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('last_activity_at');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_at');
        });
    }
};
