<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records when a user accepted the Platform Terms & Conditions of Events by
     * CK Enterprises UK. Captured at self-signup, where the Owner must agree to
     * the current Platform Terms before the account is created.
     *
     *   - users.agreed_to_terms_at : timestamp of acceptance, NULL if the user
     *     has never explicitly accepted (e.g. invited users, pre-existing
     *     accounts, Super_Admins created out-of-band).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('agreed_to_terms_at')->nullable()->after('password');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agreed_to_terms_at');
        });
    }
};
