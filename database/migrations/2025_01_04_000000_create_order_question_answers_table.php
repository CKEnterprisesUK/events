<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An `order_question_answers` row captures the Customer's answer to one
     * custom {@see event_questions} question at checkout, stored on the Order so
     * the response is retained for reporting. Modelled on `order_consents`: it
     * is Company-owned (carries `company_id`), belongs to an Order, and points
     * at the answered `event_question`.
     *
     * `question_label` snapshots the question text at answer time so a report
     * still reads correctly even if the organiser later edits or deletes the
     * question. `answer` is free text (a number is stored as its string form,
     * a select stores the chosen option) and is nullable so an optional,
     * unanswered question can still record an empty response.
     */
    public function up(): void
    {
        Schema::create('order_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // The question answered. Nulled (not deleted) if the question is
            // later removed, so the answer + its snapshot label survive.
            $table->foreignId('event_question_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot of the question text at the time it was answered.
            $table->string('question_label', 255);
            // The Customer's answer. Null = optional question left blank.
            $table->text('answer')->nullable();
            $table->timestamp('captured_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_question_answers');
    }
};
