<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `company_user` pivot turns the single-Company `users.company_id` into
     * a many-to-many membership so a Staff_User can belong to — and switch
     * between — more than one Company from the iOS app (Requirement 4). The web
     * surface is untouched: it still resolves exactly one Company from
     * `users.company_id`; this table is the membership source of truth the API
     * reads instead.
     *
     * Each membership carries the user's `role` WITHIN that Company (the same
     * closed Company-role set the User model defines), because role is a
     * property of a membership, not of the user globally — the token's abilities
     * are minted from the Active_Company's membership role.
     *
     * Columns:
     *   - company_id : the Company the membership is in. FK → companies,
     *                  cascade-delete so removing a Company removes its
     *                  memberships.
     *   - user_id    : the Staff_User. FK → users, cascade-delete so removing a
     *                  user removes their memberships.
     *   - role       : the user's Company role within THIS Company (owner,
     *                  admin, box_office, accountant, scanner).
     *   - timestamps : when the membership was created/updated, carried onto the
     *                  BelongsToMany relation via withTimestamps().
     *
     * A unique index on (company_id, user_id) guarantees a user has at most one
     * membership row per Company.
     */
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role');

            $table->timestamps();

            $table->unique(['company_id', 'user_id']);
        });

        $this->backfillExistingMemberships();
    }

    /**
     * Seed one membership row for every existing Company_User, mirroring their
     * current `(company_id, role)` so today's users keep working the moment the
     * API reads memberships instead of `users.company_id`. Super_Admins are
     * skipped — they carry a NULL `company_id`/`role` and are not part of any
     * Company's membership.
     *
     * The backfill is idempotent (it will not duplicate a row thanks to the
     * unique index and the `insertOrIgnore`) and runs inside the migration so a
     * single `migrate` leaves current users fully functional.
     */
    private function backfillExistingMemberships(): void
    {
        $now = now();

        DB::table('users')
            ->whereNotNull('company_id')
            ->whereNotNull('role')
            ->orderBy('id')
            ->select(['id', 'company_id', 'role'])
            ->chunkById(500, function ($users) use ($now) {
                $rows = $users->map(fn ($user) => [
                    'company_id' => $user->company_id,
                    'user_id' => $user->id,
                    'role' => $user->role,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('company_user')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
