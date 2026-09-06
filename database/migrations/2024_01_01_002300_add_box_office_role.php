<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add the `box_office` Company role to the `role` enums on `users` and
     * `invitations`. Box_Office is a cut-down Admin: it runs the box office
     * (events, ticket types, orders incl. cancel/refund/comp) but is not
     * trusted with Company settings, users, Stripe, billing, or GDPR handling.
     *
     * The `users.role` enum keeps `owner` in its value set (owners are seeded /
     * transferred, never invited); the `invitations.role` enum continues to
     * exclude `owner` (the Owner is not invitable, Requirement 4.6) but gains
     * `box_office` alongside the other invitable roles.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE `users` MODIFY `role` "
            ."enum('owner','admin','box_office','accountant','scanner') DEFAULT NULL"
        );

        DB::statement(
            "ALTER TABLE `invitations` MODIFY `role` "
            ."enum('admin','box_office','accountant','scanner') NOT NULL"
        );
    }

    /**
     * Reverse the migration. Any Box_Office users/invitations are first mapped
     * to a role that survives the narrowed enum so the ALTER cannot fail or
     * silently truncate: existing Box_Office users become Scanner (the least
     * privileged operational role) and any pending Box_Office invitations are
     * likewise downgraded to Scanner.
     */
    public function down(): void
    {
        DB::table('users')->where('role', 'box_office')->update(['role' => 'scanner']);
        DB::table('invitations')->where('role', 'box_office')->update(['role' => 'scanner']);

        DB::statement(
            "ALTER TABLE `users` MODIFY `role` "
            ."enum('owner','admin','accountant','scanner') DEFAULT NULL"
        );

        DB::statement(
            "ALTER TABLE `invitations` MODIFY `role` "
            ."enum('admin','accountant','scanner') NOT NULL"
        );
    }
};
