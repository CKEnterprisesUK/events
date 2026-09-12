<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `stripe_fee_minor` to `orders`: the ACTUAL card-processing fee Stripe
     * charged on the connected account for this Order, in integer minor currency
     * units, or NULL when it has not yet been captured (a free/unpaid Order, or
     * a paid Order whose balance transaction has not been retrieved yet).
     *
     * This is distinct from `application_fee_minor` (the Platform's own fee that
     * Stripe collects for us via `application_fee_amount`). Stripe's processing
     * fee is deducted inside the connected account and was previously invisible
     * to the Platform, so the "net to company" figure across the dashboard and
     * reports over-stated what actually lands in the organiser's bank. Capturing
     * the real fee here lets those surfaces show a truthful payout figure.
     *
     * The value is read from the charge's Balance Transaction on the connected
     * account after payment confirmation (webhook), so it is the exact fee Stripe
     * took, not an estimate — and it stays accurate automatically if Stripe ever
     * changes its pricing. (Truthful-payout feature)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('stripe_fee_minor')->nullable()->after('refunded_total_minor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stripe_fee_minor');
        });
    }
};
