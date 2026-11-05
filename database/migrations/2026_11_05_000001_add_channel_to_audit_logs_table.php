<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which surface produced an audit row, so the trail makes clear
     * whether an action was taken in the native iOS scanner app or on the web
     * dashboard (and distinguishes both from server-side/system events).
     *
     *   - audit_logs.channel : the origin surface of the action —
     *       `app`    the iOS scanner app (Sanctum-authenticated `/api/*` route),
     *       `web`    the web dashboard (session-authenticated route),
     *       `system` a server-side event with no request surface (webhook/queue).
     *     NULL for rows written before this column existed (origin unknown).
     *
     * The column is indexed so the Activity / Audit surfaces can filter by it.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->enum('channel', ['app', 'web', 'system'])
                ->nullable()
                ->after('actor_type')
                ->index();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
