<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `legal_documents` table backs the platform-level Trust & Legal Centre:
     * a set of Platform-wide policies (Terms & Conditions, Privacy Notice, PCI
     * DSS statement, cookie policy, and any others) authored and maintained by
     * a Super_Admin, published at public `/trust` URLs and linked from the site
     * footer.
     *
     * Unlike the per-Company `terms_text`/`privacy_text` (organiser legal shown
     * at checkout), these are Platform documents owned by Events by CK
     * Enterprises UK. Held as multiple rows keyed by a stable `slug` so the set
     * is extensible without further schema changes.
     *
     *   - slug         : stable public identifier (e.g. `terms`, `privacy`).
     *   - title        : human-readable heading shown on the page and footer.
     *   - body         : the document content (Markdown/plain text, rendered
     *                    safely on the public page). NULL/empty = not authored.
     *   - is_published : only published documents appear publicly; drafts are
     *                    editable in the admin but 404 on the public surface.
     *   - sort_order   : ordering on the Trust & Legal Centre hub page.
     */
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 100)->unique();
            $table->string('title');
            $table->longText('body')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
