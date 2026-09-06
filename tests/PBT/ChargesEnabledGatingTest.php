<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Stripe\FakeStripePaymentService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Property-based test for charges-enabled gating (design Property 15).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database and drives the real HTTP checkout endpoint under
 * `/{company-slug}/{event-id}/checkout` (Stripe is always mocked via the
 * bound {@see FakeStripePaymentService}).
 *
 * The rule under test: a PAID checkout (order total > 0) is permitted if and
 * only if the Company has a connected Stripe account (`stripe_account_id`
 * present) AND `stripe_charges_enabled` is true. A FREE-only checkout (order
 * total 0) is always permitted regardless of the charges-enabled state. A
 * blocked paid checkout creates no Order and reserves no capacity.
 *
 * This property generates random combinations of:
 *   - paid vs free cart (via the Ticket_Type price being positive or zero);
 *   - connected account present/absent (`stripe_account_id`);
 *   - `stripe_charges_enabled` true/false;
 * and asserts the iff decision plus the no-order / no-reservation guarantee for
 * blocked paid orders.
 *
 * Each iteration provisions a fresh Company + published Event + on-sale
 * Ticket_Type (never deleting prior rows) and scopes every assertion to that
 * iteration's Event, so iterations stay independent without any DDL/TRUNCATE
 * inside the test — RefreshDatabase owns the schema lifecycle.
 */
class ChargesEnabledGatingTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Property 15: Charges-enabled gating — a paid checkout is permitted iff
     * the Company has a connected Stripe account with charges enabled, while a
     * free-only checkout is always permitted; a blocked paid checkout creates
     * no Order and reserves no capacity.
     *
     * **Validates: Requirements 10.8, 11.3**
     */
    // Feature: event-ticketing-platform, Property 15: Charges-enabled gating — paid checkout permitted iff connected Stripe account with charges enabled; free-only always permitted
    public function test_paid_checkout_permitted_iff_charges_enabled_and_free_always_permitted(): void
    {
        $this->forAll(
            // Paid vs free cart.
            Generator\bool(),
            // Connected Stripe account present vs absent.
            Generator\bool(),
            // stripe_charges_enabled true vs false.
            Generator\bool(),
            // Requested quantity, 1–4.
            Generator\choose(1, 4),
        )
            ->then(function (bool $paid, bool $accountPresent, bool $chargesEnabled, int $qty): void {
                Carbon::setTestNow(Carbon::parse('2024-06-01 12:00:00'));

                // Fresh, independent Company/Event/Ticket_Type per iteration.
                // No deletes/DDL: RefreshDatabase owns the schema, and every
                // assertion below is scoped to this iteration's Event.
                $company = Company::factory()->create([
                    'stripe_account_id' => $accountPresent ? 'acct_test123' : null,
                    'stripe_charges_enabled' => $chargesEnabled,
                    'fee_handling_mode' => Company::FEE_MODE_ABSORB,
                    'company_fee_percent' => '10.00',
                ]);

                $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

                $type = TicketType::factory()->forEvent($event)->create([
                    'price_minor' => $paid ? 2_000 : 0,
                    'capacity' => 100,
                    'sold_count' => 0,
                    'reserved_count' => 0,
                    'sale_starts_at' => Carbon::now()->subDay(),
                    'sale_ends_at' => Carbon::now()->addMonth(),
                ]);

                $payload = [
                    'customer_name' => 'Ada Lovelace',
                    'customer_email' => 'ada@example.test',
                    'items' => [
                        ['ticket_type_id' => $type->id, 'quantity' => $qty],
                    ],
                    'consents' => ['terms' => true, 'privacy' => true],
                ];

                $response = $this->post("/{$company->slug}/{$event->id}/checkout", $payload);

                // A paid order requires a connected, charges-enabled account.
                // A free-only order (subtotal 0) is always permitted.
                $canAcceptPaid = $accountPresent && $chargesEnabled;
                $expectedPermitted = ! $paid || $canAcceptPaid;

                $context = sprintf(
                    'paid=%s, accountPresent=%s, chargesEnabled=%s, qty=%d',
                    $paid ? 'true' : 'false',
                    $accountPresent ? 'true' : 'false',
                    $chargesEnabled ? 'true' : 'false',
                    $qty,
                );

                // Orders/tickets scoped to THIS iteration's Event.
                $orders = Order::withoutGlobalScopes()->where('event_id', $event->id);

                if ($expectedPermitted) {
                    // Permitted: checkout redirects and an Order is reserved.
                    $response->assertRedirect();

                    $order = $orders->firstOrFail();
                    // A permitted PAID order hands off to Stripe and stays
                    // `reserved` (marked paid only by the webhook); a permitted
                    // FREE-only order confirms immediately as `free_confirmed`
                    // with no charge. (Requirements 10.9, 12.5, 12.6)
                    $this->assertSame(
                        $paid ? Order::STATUS_RESERVED : Order::STATUS_FREE_CONFIRMED,
                        $order->status,
                        "Permitted checkout must create the expected Order status ({$context}).",
                    );
                    $this->assertSame(
                        $qty,
                        Ticket::withoutGlobalScopes()->where('order_id', $order->id)->count(),
                        "Permitted checkout must create one Ticket per purchased ticket ({$context}).",
                    );
                    // A permitted PAID order stays reserved, so its capacity is
                    // held in `reserved_count`. A permitted FREE-only order is
                    // confirmed and fulfilled at checkout, so its capacity is
                    // committed from reserved to `sold_count`. Either way the
                    // requested quantity is committed to the Event. (10.6, 14.1)
                    $freshType = $type->fresh();
                    if ($paid) {
                        $this->assertSame(
                            $qty,
                            $freshType->reserved_count,
                            "Permitted paid checkout must hold capacity as reserved ({$context}).",
                        );
                        $this->assertSame(
                            0,
                            $freshType->sold_count,
                            "Permitted paid checkout must not yet sell capacity ({$context}).",
                        );
                    } else {
                        $this->assertSame(
                            0,
                            $freshType->reserved_count,
                            "Fulfilled free checkout must release the reserved hold ({$context}).",
                        );
                        $this->assertSame(
                            $qty,
                            $freshType->sold_count,
                            "Fulfilled free checkout must commit capacity as sold ({$context}).",
                        );
                    }

                    if ($paid) {
                        $this->assertGreaterThan(
                            0,
                            $order->order_total_minor,
                            "A paid order must have a positive total ({$context}).",
                        );
                    } else {
                        $this->assertSame(
                            0,
                            $order->order_total_minor,
                            "A free-only order must have a zero total ({$context}).",
                        );
                    }
                } else {
                    // Blocked paid checkout: payment error, no Order, no hold.
                    $response->assertSessionHasErrors('payment');

                    $this->assertSame(
                        0,
                        $orders->count(),
                        "Blocked paid checkout must create no Order ({$context}).",
                    );
                    $this->assertSame(
                        0,
                        $type->fresh()->reserved_count,
                        "Blocked paid checkout must reserve no capacity ({$context}).",
                    );
                }
            });
    }
}
