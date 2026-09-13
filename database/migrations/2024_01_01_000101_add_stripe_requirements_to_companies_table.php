<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Capture more of a connected account's onboarding/verification state than
     * the single `stripe_charges_enabled` flag, so the Payments page can tell
     * the Company exactly why an account is restricted (e.g. a business
     * verification document is outstanding) rather than only that charges are
     * off. These mirror the Stripe Account object's `requirements`,
     * `disabled_reason`, `payouts_enabled` and `details_submitted`, refreshed on
     * return from onboarding and on `account.updated` webhooks. (Requirements
     * 11.3, 11.4)
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Whether payouts are enabled on the connected account, distinct
            // from charges — an account can take charges but have payouts held.
            $table->boolean('stripe_payouts_enabled')->default(false)->after('stripe_charges_enabled');
            // Whether the account holder has submitted all required onboarding
            // information (Stripe `details_submitted`). False => onboarding not
            // finished.
            $table->boolean('stripe_details_submitted')->default(false)->after('stripe_payouts_enabled');
            // Stripe's machine-readable reason the account is disabled/restricted
            // (e.g. `requirements.past_due`, `requirements.pending_verification`),
            // NULL when not disabled.
            $table->string('stripe_disabled_reason')->nullable()->after('stripe_details_submitted');
            // The connected account's outstanding requirements, as returned by
            // Stripe: currently_due / past_due / pending_verification arrays plus
            // the human-facing errors. Stored verbatim so the dashboard can list
            // what the Company still needs to provide.
            $table->json('stripe_requirements')->nullable()->after('stripe_disabled_reason');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_payouts_enabled',
                'stripe_details_submitted',
                'stripe_disabled_reason',
                'stripe_requirements',
            ]);
        });
    }
};
