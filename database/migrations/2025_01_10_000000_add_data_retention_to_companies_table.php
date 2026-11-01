<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Company's customer-data retention settings, surfaced as a
     * mandatory onboarding decision and enforced by the daily
     * `customers:anonymise-expired` sweep.
     *
     *   * `retention_months` — how long the Company keeps a Customer's
     *     identifiable personal data (name/email on their Orders) after that
     *     Customer's LAST order, before it is automatically anonymised. The
     *     transactional records are always retained; only the personal data is
     *     scrubbed. Nullable: NULL means "not yet chosen", and the app falls
     *     back to the 24-month default until the Owner confirms a value.
     *   * `retention_confirmed_at` — when the Owner explicitly confirmed the
     *     retention choice. Drives the onboarding checklist step: the step is
     *     complete once this is set, so a silent default never counts as a
     *     deliberate decision.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Customer-data retention window in months. Nullable until the Owner
            // confirms a choice; the model falls back to the 24-month default.
            $table->unsignedSmallInteger('retention_months')->nullable()->after('gdpr_contact_email');
            // When the Owner explicitly confirmed the retention setting (drives
            // the onboarding checklist step). NULL until confirmed.
            $table->timestamp('retention_confirmed_at')->nullable()->after('retention_months');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['retention_months', 'retention_confirmed_at']);
        });
    }
};
