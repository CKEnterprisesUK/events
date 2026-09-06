<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An `invitations` row records that an Owner has invited a user (by email)
     * to join the Owner's Company with an assigned role. It is Company-owned
     * (carries `company_id`) and relies on the global tenant scope for
     * isolation. Per the design `invitations` data model:
     *   - `email` is the invited address.
     *   - `role` is one of exactly {admin, accountant, scanner} — Owner is NOT
     *     invitable (Requirement 4.6); the single Owner is only ever seeded or
     *     transferred, never invited.
     *   - `token` backs the accept link.
     *   - `accepted_at` records acceptance (NULL while pending). (Requirement 4.2)
     *   - `expires_at` bounds the invitation's validity.
     *
     * (Design `invitations` data model; Requirements 4.1, 4.2, 4.6.)
     */
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            // Owning Company (tenant scope; the inviting Owner's Company). (4.1)
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Invited email address.
            $table->string('email');
            // Assigned role — Owner is not invitable. (Requirement 4.6)
            $table->enum('role', ['admin', 'accountant', 'scanner']);
            // Opaque accept-link token.
            $table->string('token')->unique();
            // Acceptance timestamp; NULL while pending. (Requirement 4.2)
            $table->timestamp('accepted_at')->nullable();
            // Expiry bound of the invitation's validity. Nullable at the DB
            // level (MySQL strict mode rejects a NOT NULL TIMESTAMP without a
            // default); the application always sets it explicitly on create.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
