<?php

namespace Database\Seeders;

use App\Services\StressTestFixture;
use Illuminate\Database\Seeder;

/**
 * Seeds the synthetic door-load fixture (10 scanners, one live event and
 * thousands of confirmed, unscanned orders) for the stress-test tool.
 *
 * Thin CLI wrapper over {@see StressTestFixture}; the Super_Admin "Stress test"
 * page drives the same service from the browser. NON-PRODUCTION ONLY: the
 * fixture refuses unless APP_ENV is an allow-listed pre-prod name.
 *
 *     php artisan db:seed --class=Database\\Seeders\\StressTestSeeder
 *
 * Re-running replaces the previous fixture.
 */
class StressTestSeeder extends Seeder
{
    public function run(): void
    {
        $summary = app(StressTestFixture::class)->seed(StressTestFixture::DEFAULT_ORDERS);

        $this->command?->getOutput()->writeln(sprintf(
            'Stress-test fixture seeded: %d scanners, event #%d, %d orders, %d tickets.',
            $summary['scanners'],
            $summary['event_id'],
            $summary['orders'],
            $summary['tickets'],
        ));
    }
}
