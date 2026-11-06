<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Users created after the original `company_user` backfill (invitations,
     * registrations) never got a membership row, so the iOS app could not list
     * a company for them. Mirror (company_id, role) for every user missing one,
     * and re-sync the role for users whose role changed since.
     */
    public function up(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotNull('company_id')
            ->whereNotNull('role')
            ->orderBy('id')
            ->select(['id', 'company_id', 'role'])
            ->chunkById(500, function ($users) use ($now) {
                $rows = $users->map(fn ($u) => [
                    'company_id' => $u->company_id,
                    'user_id' => $u->id,
                    'role' => $u->role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('company_user')->upsert(
                        $rows,
                        ['company_id', 'user_id'],
                        ['role', 'updated_at'],
                    );
                }
            });
    }

    public function down(): void
    {
        // Data repair only; nothing to reverse.
    }
};
