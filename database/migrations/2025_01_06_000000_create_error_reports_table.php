<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `error_reports` table captures uncaught server errors (HTTP 500s) in
     * production so a visitor is never shown a raw stack trace, yet the failure
     * is not lost. When `APP_DEBUG=false`, the exception handler stores the raw
     * diagnostic detail here and shows the customer a short, quotable
     * `reference` (e.g. `ERR-3F9A2B7C`) on a branded error page. A Super_Admin
     * looks the reference up on the `/admin/errors` surface to see the full
     * exception behind it.
     *
     * Like `audit_logs`, this table is deliberately NOT tenant-scoped by the
     * global `company_id` scope: an error can occur on the platform surface, on
     * a webhook, or before a tenant is even resolved, so it may have no Company
     * and no acting user. `company_id`/`user_id` are plain nullable columns and
     * the admin surface reads across every tenant.
     *
     * Columns:
     *   - reference      : short, unique, customer-facing lookup code. Random
     *                      (not sequential) so it leaks no volume information.
     *   - company_id     : the tenant in context when it failed (NULL = platform
     *                      /system/pre-tenant). FK → companies ON DELETE SET NULL.
     *   - user_id        : the authenticated user in context (NULL = guest/
     *                      customer/system). FK → users ON DELETE SET NULL.
     *   - exception_class: the thrown exception's class (e.g. \RuntimeException).
     *   - message        : the exception message.
     *   - file / line    : where it was thrown.
     *   - status_code    : the HTTP status served to the client (typically 500).
     *   - method / url   : the request that triggered it.
     *   - ip_address     : the request IP (IPv4/IPv6).
     *   - user_agent     : the request User-Agent.
     *   - trace          : the full stack trace (longtext).
     *   - context        : small JSON payload (route name, input keys, headers
     *                      of interest) — PII-minimised.
     *   - resolved_at    : stamped when a Super_Admin marks it dealt with.
     *   - created_at     : when it happened. There is no `updated_at` besides
     *                      the resolve stamp, so timestamps() is used for both.
     */
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table) {
            $table->id();

            // Short, random, customer-facing lookup code. Unique so a reference
            // maps to exactly one stored error.
            $table->string('reference', 32)->unique();

            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('exception_class')->nullable();
            $table->text('message')->nullable();
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();

            $table->unsignedSmallInteger('status_code')->default(500);

            $table->string('method', 10)->nullable();
            $table->text('url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->longText('trace')->nullable();
            $table->json('context')->nullable();

            // NULL until a Super_Admin marks the report handled.
            $table->timestamp('resolved_at')->nullable();

            // created_at drives the "most recent first" listing and the
            // retention prune sweep; updated_at moves when the report is
            // resolved.
            $table->timestamps();
            $table->index('created_at');

            $table->foreign('company_id')
                ->references('id')->on('companies')
                ->nullOnDelete();
            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
