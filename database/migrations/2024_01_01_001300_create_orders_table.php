<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An `orders` row is a Customer's checkout against an Event. It is
     * Company-owned (carries `company_id`) and belongs to an Event, and relies
     * on the global tenant scope for isolation. `order_reference` is unique
     * across the whole Platform (not just the Company) so a scanned/looked-up
     * reference is globally unambiguous (Requirement 10.13). All money is held
     * in integer minor currency units; the fee breakdown
     * (`ticket_subtotal_minor`, `application_fee_minor`, `booking_fee_minor`,
     * `order_total_minor`) and the `fee_handling_mode` are SNAPSHOTTED onto the
     * Order at creation so a later change to the Company's fee mode never
     * mutates an existing Order (Requirement 13.8). `reserved_until` records the
     * 900-second reservation window; the Order starts in `reserved` status and
     * the scheduled release job expires holds whose window has elapsed
     * (Requirements 10.6, 10.7). The Stripe linkage columns and the scan
     * columns are nullable here — they are populated by the payment (task 15),
     * webhook (task 16), and scan (task 19) slices. (Design `orders` data
     * model; Requirements 10.1, 10.5, 10.6, 10.7, 10.13, 12.6, 13.8, 16.8.)
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Owning Company (tenant scope) and the Event checked out against.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Platform-wide unique reference. (Requirement 10.13)
            $table->string('order_reference')->unique();
            // Customer identity: name 1–200, email 1–254. (Requirement 10.1)
            $table->string('customer_name', 200);
            $table->string('customer_email', 254);
            // Order lifecycle status. Starts `reserved`; free-only orders are
            // confirmed immediately in a later slice. (Requirements 10.7, 10.9,
            // 12.6, 17.x)
            $table->enum('status', [
                'reserved',
                'paid',
                'free_confirmed',
                'expired',
                'cancelled',
                'refunded',
                'disputed',
                'voided',
            ])->default('reserved');
            // Money snapshot in integer minor currency units.
            $table->integer('ticket_subtotal_minor')->default(0);
            $table->integer('booking_fee_minor')->default(0);
            $table->integer('application_fee_minor')->default(0);
            $table->integer('order_total_minor')->default(0);
            // Fee mode snapshot at creation (immutable per order). (Req 13.8)
            $table->enum('fee_handling_mode', ['absorb', 'pass_on']);
            // 900-second reservation window. (Requirements 10.6, 10.7)
            $table->timestamp('reserved_until')->nullable();
            // Stripe linkage, populated by later payment/webhook slices.
            $table->string('stripe_session_id')->nullable();
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_payment_intent_id')->nullable();
            // Single check-in, populated by the scan slice. (Requirement 16.8)
            $table->timestamp('scanned_at')->nullable();
            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
