<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds optional, per-user TOTP two-factor authentication (RFC 6238) to
     * `users`. MFA is opt-in: a user enables it from their profile, scans a QR
     * into an authenticator app, and confirms with a code.
     *
     *   - two_factor_secret : the TOTP shared secret, stored ENCRYPTED (the
     *     User model casts it `encrypted`), so `text` holds the ciphertext
     *     which is far longer than the ~32-char raw secret. NULL until the user
     *     starts enrolment.
     *   - two_factor_recovery_codes : a JSON array of one-time recovery codes,
     *     stored ENCRYPTED (`encrypted:array`). NULL until enrolment. Lets a
     *     user who has lost their authenticator device still pass the challenge.
     *   - two_factor_confirmed_at : when the user verified their first code and
     *     MFA became active. NULL while a secret exists but is unconfirmed
     *     (enrolment started, not finished) and NULL when MFA is off. Only a
     *     user with a non-NULL value here is challenged at login.
     *   - mfa_prompt_dismissed_at : when the user chose "don't remind me again"
     *     on the post-login MFA recommendation nudge. NULL keeps showing the
     *     nudge at each login until they enable MFA or dismiss it.
     *
     * Mirrors database/sql/043_add_mfa_to_users.sql (the phpMyAdmin/prod copy).
     * Keep the two byte-consistent: if this migration changes, regenerate that
     * SQL file from a mysqldump diff of `users`.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
            $table->timestamp('mfa_prompt_dismissed_at')->nullable()->after('two_factor_confirmed_at');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_recovery_codes',
                'two_factor_confirmed_at',
                'mfa_prompt_dismissed_at',
            ]);
        });
    }
};
