<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `support_requests` table backs the in-dashboard "Contact support"
     * form: a Company_User raises a ticket with the platform operator (Events
     * by CK Enterprises UK / the Super_Admins) when the Help & Knowledge portal
     * has not resolved their issue.
     *
     * It is a Company-owned model (uses the tenant `BelongsToCompany` trait), so
     * every row carries the `company_id` of the raising Company and is isolated
     * to that tenant. The raising user is recorded on `user_id` (ON DELETE SET
     * NULL so the ticket survives the user being removed/anonymised).
     *
     *   - category           : coarse bucket the user chose (billing, payments,
     *                           events, technical, account, other) so operators
     *                           can triage without reading every ticket.
     *   - subject / message  : the user's one-line summary and full description.
     *   - status             : open → in_progress → resolved / closed. Managed
     *                           by the operator; `open` on creation.
     *   - access_consent      : whether the user ticked "allow CK Enterprises to
     *                           access my account to assist with this request".
     *                           This is a customer-granted, per-ticket
     *                           authorisation for Super_Admin impersonation.
     *   - access_consent_at   : when that consent was granted (NULL = never).
     *   - resolved_at         : when the ticket was moved to a terminal state.
     */
    public function up(): void
    {
        Schema::create('support_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained('companies')
                ->cascadeOnDelete();

            // The raising user. Kept even if the user is later removed/anonymised
            // so the ticket history survives, hence SET NULL rather than cascade.
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->enum('category', [
                'account',
                'events',
                'orders',
                'payments',
                'billing',
                'technical',
                'other',
            ])->default('other');

            $table->string('subject');
            $table->text('message');

            $table->enum('status', [
                'open',
                'in_progress',
                'resolved',
                'closed',
            ])->default('open');

            // Per-ticket consent for CK Enterprises (Super_Admin) to access the
            // account to assist. `access_consent_at` records when it was granted.
            $table->boolean('access_consent')->default(false);
            $table->timestamp('access_consent_at')->nullable();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_requests');
    }
};
