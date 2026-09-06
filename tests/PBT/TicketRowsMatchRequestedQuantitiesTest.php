<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Property-based test for ticket rows matching requested quantities
 * (design Property 13).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. It drives the real HTTP
 * checkout endpoint against the MySQL test database so the created Order and
 * its Ticket rows reflect production behaviour exactly.
 *
 * Each iteration builds a published Event on a charges-enabled Company with a
 * random number of on-sale Ticket_Types and a random valid cart (per-type
 * quantities constrained well within capacity so the cart is always
 * admissible). After a successful checkout it asserts:
 *   - exactly `sum(quantities)` Ticket rows exist for the created Order;
 *   - the per-Ticket_Type row counts equal the requested per-type quantities;
 *   - every Ticket row is scoped to the Order's Company (`company_id`).
 */
class TicketRowsMatchRequestedQuantitiesTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * Property 13: Ticket rows match requested quantities — one Ticket row per
     * purchased/claimed ticket; the total number of Ticket rows and the
     * per-Ticket_Type counts equal the requested quantities.
     *
     * **Validates: Requirements 10.5**
     */
    // Feature: event-ticketing-platform, Property 13: Ticket rows match requested quantities — one Ticket row per purchased/claimed ticket; totals and per-type counts equal requested quantities
    public function test_successful_checkout_creates_one_ticket_row_per_requested_ticket(): void
    {
        $this->forAll(
            // Number of distinct Ticket_Types in the cart (1–4).
            Generator\choose(1, 4),
            // A pool of per-type requested quantities; the body slices this to
            // the chosen type count. Quantities are small (1–8) and capacities
            // are large so every cart is admissible.
            Generator\tuple(
                Generator\choose(1, 8),
                Generator\choose(1, 8),
                Generator\choose(1, 8),
                Generator\choose(1, 8),
            ),
        )
            ->then(function (int $typeCount, array $quantityPool): void {
                $requested = array_slice(array_values($quantityPool), 0, $typeCount);

                // A charges-enabled Company so the (paid) checkout is permitted,
                // and a published Event with plenty of overall capacity.
                $company = Company::factory()->create([
                    'stripe_account_id' => 'acct_test123',
                    'stripe_charges_enabled' => true,
                    'fee_handling_mode' => Company::FEE_MODE_ABSORB,
                    'company_fee_percent' => '10.00',
                ]);

                $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

                // One on-sale Ticket_Type per requested line, each with capacity
                // far above the requested quantity so the cart is admissible.
                $types = [];
                foreach ($requested as $qty) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'price_minor' => 2_000,
                        'capacity' => 1_000,
                        'sold_count' => 0,
                        'reserved_count' => 0,
                        'sale_starts_at' => Carbon::now()->subDay(),
                        'sale_ends_at' => Carbon::now()->addMonth(),
                    ]);
                }

                // Build the cart: one line item per Ticket_Type with its
                // requested quantity. Track the expected per-type counts.
                $items = [];
                $expectedPerType = [];
                foreach ($types as $index => $type) {
                    $qty = $requested[$index];
                    $items[] = ['ticket_type_id' => $type->id, 'quantity' => $qty];
                    $expectedPerType[$type->id] = $qty;
                }

                $expectedTotal = array_sum($requested);

                $response = $this->post("/{$company->slug}/{$event->id}/checkout", [
                    'customer_name' => 'Ada Lovelace',
                    'customer_email' => 'ada@example.test',
                    'items' => $items,
                    'consents' => ['terms' => true, 'privacy' => true],
                ]);

                // A valid cart reserves the Order and redirects.
                $response->assertRedirect();

                $order = Order::withoutGlobalScopes()
                    ->where('event_id', $event->id)
                    ->latest('id')
                    ->firstOrFail();

                // Total Ticket rows equal the sum of requested quantities:
                // one row per purchased ticket. (Requirement 10.5)
                $this->assertSame(
                    $expectedTotal,
                    Ticket::withoutGlobalScopes()->where('order_id', $order->id)->count(),
                    "Order {$order->order_reference} must have exactly {$expectedTotal} Ticket rows."
                );

                // Per-Ticket_Type row counts equal the requested per-type
                // quantities. (Requirement 10.5)
                $actualPerType = Ticket::withoutGlobalScopes()
                    ->where('order_id', $order->id)
                    ->get()
                    ->groupBy('ticket_type_id')
                    ->map->count()
                    ->map(fn ($count): int => (int) $count)
                    ->all();

                $expectedIds = array_keys($expectedPerType);
                sort($expectedIds);
                $actualIds = array_keys($actualPerType);
                sort($actualIds);
                $this->assertSame(
                    $expectedIds,
                    $actualIds,
                    'Ticket rows must reference exactly the requested Ticket_Types.'
                );

                foreach ($expectedPerType as $ticketTypeId => $qty) {
                    $this->assertSame(
                        $qty,
                        $actualPerType[$ticketTypeId] ?? 0,
                        "Ticket_Type {$ticketTypeId} must have exactly {$qty} Ticket rows."
                    );
                }

                // Every Ticket row is scoped to the Order's Company.
                $this->assertSame(
                    0,
                    Ticket::withoutGlobalScopes()
                        ->where('order_id', $order->id)
                        ->where('company_id', '!=', $order->company_id)
                        ->count(),
                    'Every Ticket row must be scoped to the Order\'s Company.'
                );
            });
    }
}
