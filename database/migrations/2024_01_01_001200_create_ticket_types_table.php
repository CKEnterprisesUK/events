<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A `ticket_types` row is a category of ticket within an Event, carrying a
     * name, price, capacity, and sale window. It is Company-owned (carries
     * `company_id`) and belongs to an Event, and relies on the global tenant
     * scope for isolation. Money is stored in integer minor currency units
     * (`price_minor`, where 0 = free). `sold_count` tracks confirmed sales and
     * `reserved_count` tracks capacity held during active reservation windows;
     * remaining available = `capacity - sold_count - reserved_count`, enforced
     * (with the overall Event capacity) by the CapacityReservationService using
     * `SELECT ... FOR UPDATE`. (Design `ticket_types` data model; Requirements
     * 6.1, 6.3, 6.6, 6.7, 6.8, 6.9, 5.6, 10.6, 10.7.)
     */
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            // Owning Company (tenant scope) and parent Event.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Name 1–100 chars. (Requirement 6.1)
            $table->string('name', 100);
            // Price in integer minor currency units; 0 = free. (Requirements 6.1, 6.3)
            $table->integer('price_minor');
            // Capacity 1–1,000,000. (Requirement 6.1)
            $table->integer('capacity');
            // Confirmed sold; held during reservation windows.
            $table->integer('sold_count')->default(0);
            $table->integer('reserved_count')->default(0);
            // Sale window; end strictly after start. (Requirement 6.9)
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_types');
    }
};
