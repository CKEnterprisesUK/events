<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Super_Admin can disable a user account from the platform surface: a
     * reversible block that keeps the row (and its audit history) intact but
     * prevents the user from logging in and tears down any live session on
     * their next request. This is distinct from a hard delete (which removes
     * the row) and from Company suspension (which blocks a whole Company's
     * users at once).
     *
     *   - users.disabled_at : timestamp the account was disabled, NULL when the
     *     account is active. Reversible — a Super_Admin clears it to re-enable.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable()->after('last_activity_at');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
