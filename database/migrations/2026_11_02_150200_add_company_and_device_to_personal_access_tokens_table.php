<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extend Sanctum's `personal_access_tokens` with the two columns the iOS
     * scanner API binds to each token. Sanctum's own migration ships the
     * Session primitives this feature reuses — `name` (device label),
     * `abilities`, `last_used_at`, `expires_at` — so this follow-up only adds
     * what Sanctum does not model.
     *
     * Added as a SEPARATE migration (rather than editing the published Sanctum
     * one) so it applies cleanly whether or not the original has already run.
     *
     * Columns:
     *   - current_company_id : the token's Active_Company. Nullable because a
     *                          freshly issued token has not selected a Company
     *                          yet (a multi-membership user picks one after
     *                          sign-in). `CompanyController::select` writes it
     *                          and re-mints the token's `company:{id}` ability
     *                          so every later request re-scopes
     *                          (Requirements 4.3, 4.4). FK → companies with
     *                          nullOnDelete: deleting a Company just unsets the
     *                          Active_Company on surviving tokens rather than
     *                          cascading the token away.
     *   - device_id          : the Device the token is bound to. Set at
     *                          issuance and compared against the request's
     *                          `X-Device-Id` header by `ResolveApiTenant`,
     *                          which aborts 401 on mismatch before any Company
     *                          query runs (Requirements 13.3, 13.4). A
     *                          first-class indexed column keeps that comparison
     *                          a simple equality check and supports listing
     *                          Sessions by device. Nullable so any non-API
     *                          token (none today, but the column is shared)
     *                          need not carry a device.
     */
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('current_company_id')
                ->nullable()
                ->after('abilities')
                ->constrained('companies')
                ->nullOnDelete();

            $table->string('device_id')
                ->nullable()
                ->after('current_company_id')
                ->index();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_company_id');
            $table->dropIndex(['device_id']);
            $table->dropColumn('device_id');
        });
    }
};
