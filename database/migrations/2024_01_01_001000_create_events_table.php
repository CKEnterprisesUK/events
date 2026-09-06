<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An `events` row is a scheduled occurrence a Company sells tickets for,
     * addressed publicly at `events.domain/{company-slug}/{event-id}/`. It is
     * Company-owned (carries `company_id`) and relies on the global tenant
     * scope for isolation. `capacity` is the optional overall Event capacity
     * (NULL = unlimited); `is_published` gates public availability; the
     * branding columns are per-Event overrides of the Company-level branding.
     * (Design `events` data model; Requirements 5.1, 5.2, 5.4, 5.5, 7.5.)
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            // Owning Company (tenant scope). (Requirement 5.1)
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Event details.
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('venue')->nullable();
            $table->timestamp('starts_at')->nullable();
            // Optional overall capacity; NULL = unlimited. (Requirements 5.2, 5.6)
            $table->integer('capacity')->nullable();
            // Publish flag gating public availability. (Requirements 5.4, 5.5)
            $table->boolean('is_published')->default(false);
            // Event-level branding overrides (NULL = inherit Company). (7.5)
            $table->string('primary_colour', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->json('ticket_field_defs')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
