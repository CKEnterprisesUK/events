<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A `processed_webhooks` row records a Stripe webhook event the Platform has
     * already handled, keyed by the Stripe event id. The UNIQUE index on
     * `stripe_event_id` is the idempotency guard: recording the id succeeds
     * exactly once, so a redelivered event is recognised as a duplicate and its
     * heavy processing is skipped — `checkout.session.completed` marks an Order
     * paid at most once and creates no additional charge on redelivery.
     * (Requirements 12.6, 12.7, 19.3)
     *
     * Unlike most tables here it is NOT Company-owned: Stripe posts to a single
     * fixed Platform endpoint with no company slug, so there is no tenant to
     * scope the record to. (Design → processed_webhooks data model.)
     */
    public function up(): void
    {
        Schema::create('processed_webhooks', function (Blueprint $table) {
            $table->id();
            // The Stripe event id — the idempotency key. UNIQUE so a duplicate
            // delivery cannot be recorded (and therefore processed) twice.
            $table->string('stripe_event_id')->unique();
            // The Stripe event type (e.g. checkout.session.completed).
            $table->string('type');
            // When the event was recorded/processed.
            $table->timestamp('processed_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_webhooks');
    }
};
