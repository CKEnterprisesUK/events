<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `trusted_devices` table records a `(user, device)` pair that has
     * passed a two-factor (TOTP) challenge once, so later sign-ins from that
     * same device skip the challenge (Requirements 2.1, 2.4).
     *
     * Trust is per user and independent of the Active_Company — it is NOT a
     * Company-owned record — so there is no `company_id` column and no tenant
     * scope. The `device_id` is a client-supplied opaque identifier (the same
     * value the iOS client sends as `X-Device-Id`).
     *
     * Columns:
     *   - user_id      : the User the trust belongs to. FK → users, cascade on
     *                    delete so trust disappears with the account.
     *   - device_id    : client-supplied opaque device id. Indexed for lookup
     *                    by `(user_id, device_id)`.
     *   - name         : optional human device label, nullable.
     *   - trusted_at   : when the device first passed a challenge.
     *   - last_used_at : when the device last authenticated, nullable until
     *                    first reuse.
     *   - timestamps   : created_at / updated_at.
     *
     * Unique index on `(user_id, device_id)` so a device is trusted at most
     * once per user.
     */
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_id')->index();
            $table->string('name')->nullable();
            $table->timestamp('trusted_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_id']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
