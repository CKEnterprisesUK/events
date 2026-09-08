<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the Platform-wide outbound-mail transport selector to
     * `platform_settings`. A Super_Admin flips this on the platform Settings
     * page to choose how the app delivers all outgoing mail (ticket, support,
     * and diagnostic test emails):
     *
     *   - `smtp`  : the default — Laravel's configured SMTP mailer (cPanel).
     *   - `graph` : the Microsoft Graph API `sendMail` transport, sending from
     *               the organisation's own domain mailbox. Only takes effect
     *               once the Graph credentials are present in the environment;
     *               until then the app falls back to SMTP even when selected, so
     *               the toggle is always safe to leave set.
     *
     * Stored as a single-row setting alongside `global_fee_percent`. The Graph
     * credentials themselves live in the environment/config, never in the
     * database — this column only records which transport is active.
     */
    public function up(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->enum('mail_transport', ['smtp', 'graph'])
                ->default('smtp')
                ->after('global_fee_percent');
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('platform_settings', function (Blueprint $table) {
            $table->dropColumn('mail_transport');
        });
    }
};
