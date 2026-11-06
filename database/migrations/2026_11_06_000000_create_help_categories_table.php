<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `help_categories` table backs the public Knowledge Base (and the
     * in-dashboard Help portal): the top-level sections that group related
     * articles (e.g. Getting started, Events & tickets, Payments & payouts).
     *
     * This is the DB-backed replacement for the hardcoded sections that used to
     * live in `App\Support\HelpCentre`. Like the Trust & Legal Centre's
     * `legal_documents`, these are Platform-wide content owned by Events by CK
     * Enterprises UK, authored and maintained by a Super_Admin, published at the
     * public `/help` surface and linked from the site footer and the app. They
     * are NOT tenant-scoped — the same guidance is shown to every Company.
     *
     *   - slug         : stable public identifier (e.g. `getting-started`),
     *                    used in the public URL `/help/{slug}`.
     *   - title        : human-readable heading shown on the hub and page.
     *   - summary      : one-line description shown under the title.
     *   - is_published : only published categories appear publicly; drafts are
     *                    editable in the admin but hidden on the public surface.
     *   - sort_order   : ordering on the Knowledge Base hub.
     */
    public function up(): void
    {
        Schema::create('help_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title');
            $table->string('summary', 500)->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_categories');
    }
};
