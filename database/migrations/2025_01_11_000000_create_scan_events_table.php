<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `scan_events` table records EVERY scan attempt at the door — the
     * successful check-ins and, crucially, the ones that failed: a ticket
     * scanned twice (duplicate), a code that could not be read, a code that
     * verified but belongs to another organisation, or a ticket that is no
     * longer valid.
     *
     * Why a dedicated table rather than reusing `orders.scanned_at`: the Order
     * only records the ONE successful check-in. Duplicate and error outcomes
     * never touch the Order, so until now they lived only in the scanning
     * operator's own session recap (last few, per browser) and were invisible
     * to anyone else. The live event dashboard needs a central, cross-operator
     * record so the whole door team's activity — hits AND misses — shows on one
     * screen in real time.
     *
     * This is an append-only operational log, not an accounting record: the
     * authoritative "checked in" state remains `orders.scanned_at`. A row here
     * is a lightweight breadcrumb of an attempt.
     *
     * Columns:
     *   - company_id   : owning tenant (the scanning operator's Company). Scoped
     *                    by the BelongsToCompany global scope on the model.
     *   - event_id     : the Event the scan was for, when known (a successful or
     *                    duplicate scan resolves an Order and thus an Event; an
     *                    unreadable/foreign code may not, so nullable).
     *   - order_id     : the resolved Order, when the code matched one (nullable
     *                    for unreadable/invalid/foreign codes).
     *   - operator_id  : the User who performed the scan. FK → users, nullable
     *                    /nullOnDelete so a deleted operator keeps the trail.
     *   - operator_name: snapshot of the operator's name at scan time (survives
     *                    user deletion), shown on the live feed.
     *   - outcome      : the scan result bucket — success | duplicate | invalid
     *                    | rejected | unreadable | failed. Drives the feed icon
     *                    and the error tally.
     *   - reference    : the Order_Reference the code resolved to, when any.
     *   - customer_name: snapshot of the Order's customer name, for the feed.
     *   - tickets      : valid-ticket headcount on the Order, for the feed row.
     *   - created_at   : when the scan happened; indexed for "most recent first"
     *                    polling and the per-event live query. No updated_at —
     *                    rows are immutable.
     */
    public function up(): void
    {
        Schema::create('scan_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operator_name')->nullable();

            $table->enum('outcome', [
                'success',
                'duplicate',
                'invalid',
                'rejected',
                'unreadable',
                'failed',
            ])->index();

            $table->string('reference')->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->unsignedInteger('tickets')->default(0);

            $table->timestamp('created_at')->nullable();

            // The live dashboard's hot path: "this event's scans, newest first".
            $table->index(['event_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_events');
    }
};
