<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An `order_consents` row captures a single consent selection the Customer
     * made at checkout (e.g. `terms`, `privacy`, `marketing`), stored on the
     * Order so the accepted/declined state is retained (Requirements 10.3,
     * 10.4, 22.4). It is Company-owned (carries `company_id`) and belongs to an
     * Order, and relies on the global tenant scope for isolation. `accepted`
     * records whether the consent was given and `captured_at` when. (Design
     * `order_consents` data model; Requirements 10.3, 10.4, 22.4.)
     */
    public function up(): void
    {
        Schema::create('order_consents', function (Blueprint $table) {
            $table->id();
            // Owning Company (tenant scope) and parent Order.
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // The consent identifier (e.g. terms, privacy, marketing).
            $table->string('consent_key');
            // Whether the consent was accepted at checkout. (Requirements 10.3, 10.4, 22.4)
            $table->boolean('accepted');
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_consents');
    }
};
