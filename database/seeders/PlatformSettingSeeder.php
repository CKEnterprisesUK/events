<?php

namespace Database\Seeders;

use App\Models\PlatformSetting;
use Illuminate\Database\Seeder;

/**
 * Seeds the single `platform_settings` row with the default
 * `global_fee_percent` so a fresh database has the required Platform-wide
 * config. Idempotent: reruns leave the existing row untouched. (Requirement
 * 20.5; design "Seed SQL for platform settings".)
 */
class PlatformSettingSeeder extends Seeder
{
    public function run(): void
    {
        PlatformSetting::query()->firstOrCreate([], [
            'global_fee_percent' => PlatformSetting::DEFAULT_GLOBAL_FEE_PERCENT,
        ]);
    }
}
