<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: filtering the Customers roster by event and by latest marketing
 * preference.
 */
class CustomerFilterTest extends TestCase
{
    use RefreshDatabase;

    private function adminForCompany(Company $company): User
    {
        return User::factory()->admin()->create(['company_id' => $company->id]);
    }

    public function test_roster_can_be_filtered_by_event(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);

        $eventA = Event::factory()->for($company)->create(['name' => 'Concert A']);
        $eventB = Event::factory()->for($company)->create(['name' => 'Concert B']);

        Order::factory()->forEvent($eventA)->create([
            'status' => Order::STATUS_PAID,
            'customer_name' => 'Alice',
            'customer_email' => 'alice@test.test',
        ]);
        Order::factory()->forEvent($eventB)->create([
            'status' => Order::STATUS_PAID,
            'customer_name' => 'Bob',
            'customer_email' => 'bob@test.test',
        ]);

        $response = $this->actingAs($admin)
            ->get(route('dashboard.customers.index', ['event' => $eventA->id]));

        $response->assertOk();
        $response->assertSee('alice@test.test');
        $response->assertDontSee('bob@test.test');
    }

    public function test_roster_can_be_filtered_by_latest_marketing_preference(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create();

        // Opted-in customer.
        $inOrder = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'customer_email' => 'optedin@test.test',
        ]);
        OrderConsent::factory()->forOrder($inOrder)
            ->key(OrderConsent::KEY_MARKETING, true)->create();

        // Opted-out customer.
        $outOrder = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'customer_email' => 'optedout@test.test',
        ]);
        OrderConsent::factory()->forOrder($outOrder)
            ->key(OrderConsent::KEY_MARKETING, false)->create();

        // Customer who never saw the marketing opt-in.
        Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'customer_email' => 'noprefs@test.test',
        ]);

        $optedIn = $this->actingAs($admin)
            ->get(route('dashboard.customers.index', ['marketing' => 'in']));
        $optedIn->assertOk();
        $optedIn->assertSee('optedin@test.test');
        $optedIn->assertDontSee('optedout@test.test');
        $optedIn->assertDontSee('noprefs@test.test');

        $notIn = $this->actingAs($admin)
            ->get(route('dashboard.customers.index', ['marketing' => 'out']));
        $notIn->assertOk();
        $notIn->assertSee('optedout@test.test');
        $notIn->assertSee('noprefs@test.test');
        $notIn->assertDontSee('optedin@test.test');
    }

    public function test_latest_marketing_consent_wins_over_earlier_ones(): void
    {
        $company = Company::factory()->create();
        $admin = $this->adminForCompany($company);
        $event = Event::factory()->for($company)->create();

        // Same customer, two orders: an earlier opt-in, then a later opt-out.
        // The newest preference (opted out) should decide the roster grouping.
        $earlier = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'customer_email' => 'changed@test.test',
            'created_at' => now()->subDays(2),
        ]);
        OrderConsent::factory()->forOrder($earlier)
            ->key(OrderConsent::KEY_MARKETING, true)
            ->create(['captured_at' => now()->subDays(2)]);

        $later = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'customer_email' => 'changed@test.test',
            'created_at' => now(),
        ]);
        OrderConsent::factory()->forOrder($later)
            ->key(OrderConsent::KEY_MARKETING, false)
            ->create(['captured_at' => now()]);

        // They should NOT appear under "opted in"...
        $this->actingAs($admin)
            ->get(route('dashboard.customers.index', ['marketing' => 'in']))
            ->assertDontSee('changed@test.test');

        // ...but SHOULD appear under "not opted in".
        $this->actingAs($admin)
            ->get(route('dashboard.customers.index', ['marketing' => 'out']))
            ->assertSee('changed@test.test');
    }
}
