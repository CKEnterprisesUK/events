<?php

namespace Database\Seeders;

use App\Models\ReservedSlug;
use Illuminate\Database\Seeder;

/**
 * Seeds the system Company_Slug blocklist (technical/infra reserved words and
 * brand/abuse names) so a fresh database can never hand out a slug that shadows
 * a real route. Idempotent: reruns never overwrite an existing row. (See
 * {@see \App\Models\ReservedSlug} and design "Company slug blocklist".)
 */
class ReservedSlugSeeder extends Seeder
{
    public function run(): void
    {
        ReservedSlug::ensureSystemDefaults();
    }
}
