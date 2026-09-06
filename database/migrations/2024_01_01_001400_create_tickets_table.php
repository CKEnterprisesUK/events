<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A `tickets` row is a single purchased or claimed ticket within an Order:
     * the Platform creates exactly one Ticket per purchased/claimed ticket,
     * recording its Ticket_Type (Requirement 10.5). It is Company-owned
     * (carries `company_id`) and belongs to an Order and a Ticket_Type, and
     * relies on the global tenant scope for isolation. `status` is `valid` on
     * creation and flipped to `voided` when the Order is cancelled/refunded in
     * a later slice (Requirement 17.3). (Design `tickets` data model;
     * Requirements 10.5, 17.3.)
     */
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            // Owning Company (tenant scope), parent Order, and its Ticket_Type.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->cascadeOnDelete();
            // Voided on cancel/refund in a later slice. (Requirement 17.3)
            $table->enum('status', ['valid', 'voided'])->default('valid');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
