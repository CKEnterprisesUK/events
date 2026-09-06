<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds `refunded_total_minor` to `orders`: the cumulative amount refunded
     * back to the Customer for this Order, in integer minor currency units,
     * defaulting to 0. It lets a paid Order be PARTIALLY refunded one or more
     * times up to `order_total_minor` while the Order stays `paid` and its
     * Tickets stay valid; only once the cumulative refunds reach the full total
     * does the Order flip to the terminal `refunded` state (voiding Tickets and
     * returning capacity via the existing terminal path). A full refund in one
     * step is just the special case where the first partial refund equals the
     * remaining balance. (Requirement 17.2)
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('refunded_total_minor')->default(0)->after('order_total_minor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refunded_total_minor');
        });
    }
};
