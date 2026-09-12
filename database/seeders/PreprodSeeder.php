<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Pre-production sample data.
 *
 * Builds a small-but-realistic, prod-LIKE dataset from the model factories so
 * the staging subdomain can be tested against believable content without ever
 * touching real customer data. Everything here is SYNTHETIC (faker-generated):
 * no real PII, no live Stripe objects, no GDPR exposure.
 *
 * Deliberately LIGHTWEIGHT. Shared cPanel web requests are capped by
 * `max_execution_time` (~30–60s); this dataset seeds in a couple of seconds so
 * it is safe to trigger through a web request (the Super_Admin rebuild button)
 * as well as from the CLI. Keep it small — a handful of companies is plenty to
 * test against; do not grow it into thousands of rows.
 *
 * NON-PRODUCTION ONLY. DatabaseSeeder guards this behind APP_ENV !== production.
 *
 * Known logins (password: "password" for all):
 *   - super admin      super@preprod.test
 *   - company owner    owner@preprod.test   (first company)
 */
class PreprodSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The password shared by every seeded login, for easy manual testing.
     */
    private const DEMO_PASSWORD = 'password';

    public function run(): void
    {
        // A CK Enterprises Super_Admin to reach the /admin surface.
        User::factory()->superAdmin()->create([
            'name' => 'Preprod Super Admin',
            'email' => 'super@preprod.test',
        ]);

        // A primary company with a known Owner login for day-to-day testing,
        // fully Stripe-ready so it can take payments and publish paid events.
        $primary = Company::factory()->stripeReady()->create([
            'name' => 'Preprod Demo Co',
        ]);
        User::factory()->owner()->for($primary)->create([
            'name' => 'Preprod Owner',
            'email' => 'owner@preprod.test',
        ]);
        $this->seedCompanyTeam($primary);
        $this->seedEventsAndOrders($primary, publishedEvents: 3, draftEvents: 1);

        // A second Stripe-ready company (random owner) for cross-tenant testing.
        $second = Company::factory()->stripeReady()->create();
        User::factory()->owner()->for($second)->create();
        $this->seedCompanyTeam($second);
        $this->seedEventsAndOrders($second, publishedEvents: 2, draftEvents: 1);

        // A charity, and a suspended company, to exercise those states/branches.
        $charity = Company::factory()->charity()->stripeReady()->create();
        User::factory()->owner()->for($charity)->create();
        $this->seedEventsAndOrders($charity, publishedEvents: 1, draftEvents: 0);

        $suspended = Company::factory()->suspended()->create();
        User::factory()->owner()->for($suspended)->create();
    }

    /**
     * Give a company a spread of non-owner staff across the roles.
     */
    private function seedCompanyTeam(Company $company): void
    {
        User::factory()->admin()->for($company)->create();
        User::factory()->boxOffice()->for($company)->create();
        User::factory()->accountant()->for($company)->create();
        User::factory()->scanner()->for($company)->create();
    }

    /**
     * Build published + draft events for a company, each with a couple of
     * ticket types, and a spread of orders across the lifecycle with matching
     * tickets. A scanner from the company is used to stamp scanned orders.
     */
    private function seedEventsAndOrders(Company $company, int $publishedEvents, int $draftEvents): void
    {
        $scanner = User::factory()->scanner()->for($company)->create();

        for ($i = 0; $i < $publishedEvents; $i++) {
            $event = Event::factory()->published()->for($company)->create();

            $paidType = TicketType::factory()->forEvent($event)->create();
            $freeType = TicketType::factory()->forEvent($event)->free()->create();

            // Paid orders (some scanned in at the door).
            foreach (range(1, 4) as $n) {
                $order = Order::factory()
                    ->forEvent($event)
                    ->paid()
                    ->when($n <= 2, fn ($f) => $f->scanned()->state(['scanned_by' => $scanner->id]))
                    ->create();
                $this->attachTickets($order, $paidType, count: fake()->numberBetween(1, 3));
            }

            // A partially refunded and a fully refunded paid order.
            $this->attachTickets(
                Order::factory()->forEvent($event)->partiallyRefunded()->create(),
                $paidType,
                count: 2,
            );
            $this->attachTickets(
                Order::factory()->forEvent($event)->refunded()->create(),
                $paidType,
                count: 1,
            );

            // A free confirmed order, and a live reservation still within window.
            $this->attachTickets(
                Order::factory()->forEvent($event)->freeConfirmed()->create(),
                $freeType,
                count: 2,
            );
            Order::factory()->forEvent($event)->create(); // reserved, in-window
        }

        for ($i = 0; $i < $draftEvents; $i++) {
            $event = Event::factory()->unpublished()->for($company)->create();
            TicketType::factory()->forEvent($event)->create();
        }
    }

    /**
     * Create N valid tickets for an order against a ticket type.
     */
    private function attachTickets(Order $order, TicketType $type, int $count): void
    {
        Ticket::factory()
            ->count($count)
            ->forOrder($order)
            ->forTicketType($type)
            ->create();
    }
}
