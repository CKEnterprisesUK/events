<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // The single Platform-wide config row (default global fee percent).
        $this->call(PlatformSettingSeeder::class);

        // The system Company_Slug blocklist (reserved routes / infra / brand).
        $this->call(ReservedSlugSeeder::class);

        // The Knowledge Base content (public Help centre). Real product
        // guidance, not sample data, so it seeds in every environment —
        // idempotent (upsert by slug) so it is safe to re-run.
        $this->call(HelpContentSeeder::class);

        // Realistic, prod-LIKE sample data for the pre-prod/staging subdomain
        // and local development. Guarded so it can NEVER run on production:
        // production schema/data is managed deliberately, never faker-seeded.
        if (! app()->environment('production')) {
            $this->call(PreprodSeeder::class);
        }
    }
}
