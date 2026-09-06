<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Example-based tests for {@see Event::sellThrough()} — the dashboard's
 * capacity/sell-through summary for an Event.
 *
 * Pins down the capacity-resolution rules and the percentage maths:
 *   - an overall Event capacity is the ceiling and wins over per-type sums;
 *   - with no overall capacity, the ceiling is the sum of the capped
 *     per-type capacities (shared-pool types contribute no ceiling);
 *   - no resolvable positive ceiling means "unlimited" (capacity null, no %);
 *   - sold is the sum of `sold_count` across the Event's ticket types;
 *   - the percentage is clamped to 100 for an oversold event.
 */
class EventSellThroughTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    private function makeEvent(array $attributes = []): Event
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->create($attributes);

        app(TenantContext::class)->setCompany($company);

        return $event;
    }

    public function test_overall_capacity_is_the_ceiling(): void
    {
        $event = $this->makeEvent(['capacity' => 200]);
        TicketType::factory()->forEvent($event)->create([
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => 50,
            'sold_count' => 40,
        ]);
        TicketType::factory()->forEvent($event)->create([
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => 50,
            'sold_count' => 102,
        ]);

        $result = $event->sellThrough();

        // Overall 200 wins over the summed per-type 100; sold = 40 + 102 = 142.
        $this->assertSame(142, $result['sold']);
        $this->assertSame(200, $result['capacity']);
        $this->assertSame(71.0, $result['percent']);
    }

    public function test_falls_back_to_summed_capped_capacity(): void
    {
        $event = $this->makeEvent(['capacity' => null]);
        TicketType::factory()->forEvent($event)->create([
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => 30,
            'sold_count' => 15,
        ]);
        TicketType::factory()->forEvent($event)->create([
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => 70,
            'sold_count' => 10,
        ]);

        $result = $event->sellThrough();

        // No overall cap => ceiling is 30 + 70 = 100; sold = 25 => 25.0%.
        $this->assertSame(25, $result['sold']);
        $this->assertSame(100, $result['capacity']);
        $this->assertSame(25.0, $result['percent']);
    }

    public function test_shared_pool_only_with_no_overall_cap_is_unlimited(): void
    {
        $event = $this->makeEvent(['capacity' => null]);
        TicketType::factory()->forEvent($event)->create([
            'capacity_mode' => TicketType::MODE_SHARED_POOL,
            'capacity' => null,
            'sold_count' => 12,
        ]);

        $result = $event->sellThrough();

        // No capped ceiling and no overall cap => unlimited: sold known, no %.
        $this->assertSame(12, $result['sold']);
        $this->assertNull($result['capacity']);
        $this->assertNull($result['percent']);
    }

    public function test_oversold_percentage_is_clamped_to_one_hundred(): void
    {
        $event = $this->makeEvent(['capacity' => 100]);
        TicketType::factory()->forEvent($event)->create([
            'capacity_mode' => TicketType::MODE_CAPPED,
            'capacity' => 100,
            'sold_count' => 130,
        ]);

        $result = $event->sellThrough();

        $this->assertSame(130, $result['sold']);
        $this->assertSame(100, $result['capacity']);
        $this->assertSame(100.0, $result['percent']);
    }
}
