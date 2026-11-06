<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The dashboard sales chart defaults to the window that fits how long the
 * company has been selling, unless a range is chosen explicitly.
 */
class DashboardSalesChartRangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: int|null, 1: string}>
     */
    public static function sellingHistoryProvider(): array
    {
        return [
            'no sales yet' => [null, '30d'],
            'first sale yesterday' => [1, '7d'],
            'first sale 7 days ago' => [7, '30d'],
            'first sale 30 days ago' => [30, '90d'],
            'first sale 89 days ago' => [89, '90d'],
            'first sale 90 days ago' => [90, '12m'],
        ];
    }

    #[DataProvider('sellingHistoryProvider')]
    public function test_default_range_fits_the_selling_history(?int $firstSaleDaysAgo, string $expectedRange): void
    {
        $this->freezeTime();
        $owner = User::factory()->owner()->create();

        if ($firstSaleDaysAgo !== null) {
            $this->createConfirmedOrder($owner->company_id, $firstSaleDaysAgo);
            $this->createConfirmedOrder($owner->company_id, 0);
        }

        $this->actingAs($owner)
            ->get(route('dashboard.home'))
            ->assertOk()
            ->assertViewHas('chartRange', $expectedRange);
    }

    public function test_unconfirmed_orders_do_not_count_as_selling_history(): void
    {
        $this->freezeTime();
        $owner = User::factory()->owner()->create();
        $this->createConfirmedOrder($owner->company_id, 1);
        $event = Event::factory()->for(Company::find($owner->company_id))->create();
        Order::factory()->forEvent($event)->create(['created_at' => now()->subDays(200)]);

        $this->actingAs($owner)
            ->get(route('dashboard.home'))
            ->assertViewHas('chartRange', '7d');
    }

    public function test_explicit_range_overrides_the_default(): void
    {
        $this->freezeTime();
        $owner = User::factory()->owner()->create();
        $this->createConfirmedOrder($owner->company_id, 1);

        $this->actingAs($owner)
            ->get(route('dashboard.home', ['range' => '12m']))
            ->assertViewHas('chartRange', '12m');
    }

    private function createConfirmedOrder(int $companyId, int $daysAgo): void
    {
        $event = Event::factory()->for(Company::find($companyId))->create();

        Order::factory()->forEvent($event)->paid()->create(['created_at' => now()->subDays($daysAgo)]);
    }
}
