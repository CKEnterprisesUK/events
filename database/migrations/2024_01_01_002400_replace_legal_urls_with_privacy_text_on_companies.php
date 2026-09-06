<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Move the organiser's legal content in-app rather than linking out to it.
     *
     * The Terms & Conditions and Privacy Notice are now authored within the app
     * and shown to the customer on request at checkout, so the external-link
     * columns are dropped. Terms already have an in-app `terms_text` column; the
     * Privacy Notice gains a matching `privacy_text` column here.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // In-app Privacy Notice text, shown to the customer on request at
            // checkout (mirrors the existing `terms_text`).
            $table->text('privacy_text')->nullable()->after('terms_text');
        });

        Schema::table('companies', function (Blueprint $table) {
            // The organiser's legal pages are authored in-app now, not linked.
            $table->dropColumn(['terms_url', 'privacy_url']);
        });
    }

    /**
     * Reverse the migration: restore the external-link columns and drop the
     * in-app Privacy Notice text.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('terms_url', 255)->nullable()->after('linkedin_url');
            $table->string('privacy_url', 255)->nullable()->after('terms_url');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('privacy_text');
        });
    }
};
