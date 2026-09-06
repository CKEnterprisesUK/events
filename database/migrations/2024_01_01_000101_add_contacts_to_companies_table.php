<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Company contact addresses surfaced during onboarding: a public
     * support contact for Customers and a GDPR/data-protection contact for
     * data-subject requests. Both are Company settings managed by the Owner and
     * are part of the initial account setup checklist on the dashboard.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Public-facing support contact for Customers. Nullable until the
            // Owner completes onboarding.
            $table->string('support_email', 254)->nullable()->after('terms_text');
            // Data-protection / GDPR contact for data-subject requests.
            $table->string('gdpr_contact_email', 254)->nullable()->after('support_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['support_email', 'gdpr_contact_email']);
        });
    }
};
