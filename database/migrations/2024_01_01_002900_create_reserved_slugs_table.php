<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The `reserved_slugs` table backs the Company_Slug blocklist: slugs that
     * may never be claimed by a Company at self-signup or on a slug change.
     *
     * Path-based tenancy routes every storefront at `/{company-slug}/...`, so a
     * slug that collides with a reserved top-level prefix (`login`, `admin`,
     * `dashboard`, `trust`, `webhooks`, ...) or an infrastructure path (`api`,
     * `assets`, `.well-known`, ...) would create an unreachable or confusing
     * storefront and could break silently if a future reserved prefix is added.
     * This table also holds brand/abuse words (profanity, impersonation-friendly
     * names) the Platform declines to hand out.
     *
     * Enforced by {@see \App\Rules\CompanySlug}. Managed by a Super_Admin on the
     * `/admin` surface. Held as rows keyed by a stable, lowercase `slug` so the
     * list is extensible without further schema changes.
     *
     *   - slug      : the blocked value, lowercase, UNIQUE.
     *   - reason    : optional operator note explaining why it is blocked.
     *   - is_system : seeded technical/infra reserved words that the admin UI
     *                 surfaces but cannot delete (removing them would let a
     *                 storefront shadow a real route). Operator-added words are
     *                 is_system = false and freely removable.
     */
    public function up(): void
    {
        Schema::create('reserved_slugs', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 255)->unique();
            $table->string('reason')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserved_slugs');
    }
};
