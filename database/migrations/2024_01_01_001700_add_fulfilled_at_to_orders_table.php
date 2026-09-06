<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `fulfilled_at` timestamp to `orders`.
     *
     * When an Order is confirmed (paid via the webhook, or free-confirmed at
     * checkout) the Platform fulfils it exactly once: it commits the held
     * capacity from reserved to sold, generates the QR, and enqueues the ticket
     * email (Requirements 14.1, 14.3). `fulfilled_at` is the idempotency guard
     * for that step — {@see \App\Services\OrderFulfilmentService} stamps it
     * inside the same locked transaction that commits the capacity, so a
     * redelivered webhook, a retried job, or a double confirmation never
     * double-counts `sold_count` or re-enqueues the email. It is NULL until the
     * Order is fulfilled. (Design → OrderFulfilmentService; Requirement 14.3)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Stamped once when the Order is fulfilled; NULL beforehand. Placed
            // after the scan columns so it reads with the lifecycle columns.
            $table->timestamp('fulfilled_at')->nullable()->after('scanned_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('fulfilled_at');
        });
    }
};
