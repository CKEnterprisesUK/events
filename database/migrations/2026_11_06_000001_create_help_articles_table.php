<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `help_articles` table backs the public Knowledge Base: the individual
     * "how do I…?" / "why did…?" articles that live under a `help_categories`
     * section. Together with `help_categories` this is the DB-backed replacement
     * for the hardcoded knowledge base that used to live in
     * `App\Support\HelpCentre`.
     *
     * Platform-wide content owned by Events by CK Enterprises UK, authored by a
     * Super_Admin and published at `/help/{category}/{article}`. NOT tenant
     * scoped. Keyed by a `slug` that is unique within its category so the public
     * URL is stable and readable.
     *
     *   - help_category_id : owning section (FK, ON DELETE CASCADE).
     *   - slug             : stable identifier, unique within the category.
     *   - title            : article heading.
     *   - body             : the article content (Markdown, rendered safely on
     *                        the public page). NULL/empty = not authored.
     *   - is_published     : only published articles appear publicly.
     *   - sort_order       : ordering within the category.
     */
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('help_category_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 150);
            $table->string('title');
            $table->longText('body')->nullable();
            $table->boolean('is_published')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['help_category_id', 'slug']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('help_articles');
    }
};
