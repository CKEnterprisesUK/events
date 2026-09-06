<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `audit_logs` table is an append-only trail of security-, money-,
     * access-, and privacy-sensitive actions: who did what, to what, when, and
     * from where. Two audiences read it — organisers (Owner/Admin) see activity
     * for their own Company; Super_Admins see everything, and crucially see
     * their own impersonated actions flagged as staff activity.
     *
     * It deliberately does NOT use the `BelongsToCompany` global tenant scope:
     *   - system/webhook events have no acting user and may have no tenant;
     *   - Super_Admin actions must be visible cross-tenant.
     * Instead `company_id` is a plain nullable column and callers scope
     * explicitly (the same approach used for `users`/`invitations`).
     *
     * Columns:
     *   - company_id           : the tenant the action affected (NULL = platform
     *                            /system). FK → companies ON DELETE SET NULL so
     *                            purging a Company keeps the trail intact.
     *   - actor_user_id        : who performed it (NULL = system/customer). FK →
     *                            users ON DELETE SET NULL.
     *   - actor_label          : snapshot of the actor at the time (survives user
     *                            deletion/anonymisation).
     *   - actor_type           : how to interpret the actor (user/super_admin/
     *                            system/customer) — drives display.
     *   - is_impersonated      : true when a Super_Admin performed a Company
     *                            action while impersonating (the accountability
     *                            flag).
     *   - impersonator_user_id : the Super_Admin's real id when impersonated. FK
     *                            → users ON DELETE SET NULL.
     *   - action               : stable machine key, e.g. `order.refunded`.
     *   - auditable_type/_id   : the polymorphic subject (order, event, ...).
     *   - summary              : pre-rendered human sentence for the list view.
     *   - context              : small PII-minimised JSON payload for detail
     *                            /filtering (amounts in minor units, references).
     *   - ip_address           : request IP (IPv4/IPv6).
     *   - created_at           : when it happened. There is NO updated_at — rows
     *                            are immutable once written.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->string('actor_label')->nullable();
            $table->enum('actor_type', ['user', 'super_admin', 'system', 'customer'])
                ->default('user');

            $table->boolean('is_impersonated')->default(false);
            $table->unsignedBigInteger('impersonator_user_id')->nullable();

            $table->string('action', 100)->index();

            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();

            $table->string('summary', 500)->nullable();
            $table->json('context')->nullable();

            $table->string('ip_address', 45)->nullable();

            // Immutable rows: only the creation instant is recorded, indexed for
            // the "most recent first" listing and the retention prune sweep.
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['company_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);

            $table->foreign('company_id')
                ->references('id')->on('companies')
                ->nullOnDelete();
            $table->foreign('actor_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
            $table->foreign('impersonator_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
