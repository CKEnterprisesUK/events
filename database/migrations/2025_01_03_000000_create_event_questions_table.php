<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An `event_questions` row is one custom question an organiser configures
     * for an Event, asked of the Customer at checkout. An Event may carry up to
     * three questions (the ceiling is enforced in application code, not the
     * schema).
     *
     * Each question has a `type` — `free_text`, `select` (single-choice, offered
     * as radios), or `number` — a `label` shown to the Customer, an optional
     * `options` list (a JSON array, used only by `select`), a `required` flag,
     * and a `position` (0-based) driving display + column order in reports.
     *
     * Questions are Company-owned (carry `company_id` for the global tenant
     * scope) and belong to an Event, both cascading on delete.
     */
    public function up(): void
    {
        Schema::create('event_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // free_text | select | number (validated in code against
            // EventQuestion::TYPES).
            $table->string('type', 20);
            // The question text shown to the Customer at checkout.
            $table->string('label', 255);
            // Choices for `select` questions; null for free_text/number.
            $table->json('options')->nullable();
            // Whether the Customer must answer to complete the purchase.
            $table->boolean('required')->default(false);
            // 0-based display/report order within the Event.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_questions');
    }
};
