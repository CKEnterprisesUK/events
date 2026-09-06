<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The `users` table holds every Company_User (Owner/Admin/Accountant/
     * Scanner) plus CK Enterprises Super_Admins. Per the design `users` data
     * model:
     *   - `company_id` is NULL for Super_Admins (they belong to no Company) and
     *     set for Company_Users; it is intentionally NOT globally scoped —
     *     users are queried in auth/dashboard context (reserved prefixes) where
     *     no tenant is resolved, so the User model does not use the
     *     `BelongsToCompany` global scope.
     *   - `role` is one of exactly four Company roles, NULL for Super_Admins.
     *   - `last_activity_at` backs the 30-minute idle timeout (Requirement 3.11).
     *
     * The single-Owner invariant (Requirement 3.2) is enforced both in the
     * `RoleService` and at the database level. MariaDB/MySQL has no native
     * partial/filtered unique index, so we emulate one with a generated column
     * (`owner_company_id`) that equals `company_id` only while `role = 'owner'`
     * and is NULL otherwise; a UNIQUE index over that column then permits at
     * most one Owner row per Company while leaving non-owner rows unconstrained
     * (NULLs are not considered equal in a UNIQUE index).
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            // NULL for Super_Admins; set for Company_Users. Not globally scoped:
            // users are resolved in auth/dashboard context, not under a tenant.
            // The FK to `companies` is added by the companies migration, which
            // runs after this framework-scaffold migration (filename order).
            $table->foreignId('company_id')->nullable()->index();
            // Super-admin flag (separate dashboard surface). (20.1, 20.7)
            $table->boolean('is_super_admin')->default(false);
            // Exactly four Company roles; NULL for Super_Admins. (3.1)
            $table->enum('role', ['owner', 'admin', 'accountant', 'scanner'])->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // Backs the 30-minute idle-timeout check. (3.11)
            $table->timestamp('last_activity_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // Generated column + UNIQUE index emulating a partial unique index:
        // enforce at most one `owner` per `company_id` at the database level
        // (Requirement 3.2). `owner_company_id` is `company_id` only while the
        // row's role is 'owner', else NULL; NULLs are exempt from UNIQUE, so
        // only Owner rows compete for uniqueness within a Company.
        DB::statement(
            'ALTER TABLE `users` ADD COLUMN `owner_company_id` BIGINT UNSIGNED '
            ."GENERATED ALWAYS AS (CASE WHEN `role` = 'owner' THEN `company_id` ELSE NULL END) VIRTUAL"
        );
        DB::statement(
            'ALTER TABLE `users` ADD UNIQUE `users_one_owner_per_company_unique` (`owner_company_id`)'
        );

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
