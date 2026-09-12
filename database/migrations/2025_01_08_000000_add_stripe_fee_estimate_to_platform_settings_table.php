<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a CONFIGURABLE estimate of Stripe's own card-processing fee to
     * `platform_settings`, so the pre-purchase calculator and the checkout
     * preview can show buyers/organisers an approximate Stripe cut BEFORE a
     * payment settles (the exact fee is only known afterwards, from the balance
     * transaction — see orders.stripe_fee_minor).
     *
     *   - `stripe_fee_percent`     — percentage component (DECIMAL(5,2)), e.g.
     *                                1.50 for UK standard pricing.
     *   - `stripe_fee_fixed_minor` — fixed component in integer minor units,
     *                                e.g. 20 for £0.20.
     *
     * These live in the DATABASE (editable at runtime by a Super_Admin), NOT in
     * .env or config, mirroring how the platform fee (`global_fee_percent`) is
     * configured. Nothing about Stripe's pricing is hardcoded in code or env.
     * Defaults reflect Stripe's UK standard pricing at time of writing (1.5% +
     * £0.20); a Super_Admin adjusts them if Stripe's rates change. This is only
     * an ESTIMATE for display — realised reporting uses the actual captured fee.
     * (Truthful-payout / configurable-estimate feature)
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->decimal('stripe_fee_percent', 5, 2)->default('1.50')->after('global_fee_percent');
            $table->integer('stripe_fee_fixed_minor')->default(20)->after('stripe_fee_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn(['stripe_fee_percent', 'stripe_fee_fixed_minor']);
        });
    }
};
