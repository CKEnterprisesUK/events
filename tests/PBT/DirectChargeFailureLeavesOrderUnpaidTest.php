<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Property-based test for direct-charge failure leaving an Order unpaid
 * (Property 22).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database and drives the real HTTP checkout endpoint under
 * `/{company-slug}/{event-id}/checkout`; Stripe is ALWAYS mocked via the
 * container-bound {@see FakeStripePaymentService} (no live API, no card data).
 *
 * The rule under test (Requirement 12.8): a failed/cancelled direct charge
 * NEVER leaves the Order paid, transfers no funds to the Company, and surfaces
 * an error / not-completed indication. Because a paid Order is only ever marked
 * `paid` by the `checkout.session.completed` webhook (task 16, not exercised
 * here), any failure path — the Customer cancels, or the Stripe Checkout
 * Session creation fails — must leave the Order in a non-`paid` state:
 *
 *   - Customer-cancel path: the display-only cancel return releases the held
 *     capacity and marks a still-`reserved` Order `cancelled`; it is never
 *     `paid` and no funds move.
 *   - Session-creation-failure path: the Stripe boundary throws, no charge is
 *     created and no funds move; the Order is left non-`paid` (`reserved`,
 *     awaiting a webhook that never comes) with its capacity accounted for.
 *
 * Generators produce random PAID carts (1–4 on-sale Ticket_Types with random
 * prices and quantities against a connected, charges-enabled Company under a
 * random Fee_Handling_Mode + fee percent) and a random failure scenario, so
 * both the cancel and session-failure facets are exercised.
 */
class DirectChargeFailureLeavesOrderUnpaidTest extends PbtTestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * Property 22: Direct-charge failure leaves order unpaid — a failed/cancelled
     * direct charge leaves the Order unpaid (never `paid`), transfers no funds to
     * the Company, releases held capacity on cancel, and surfaces an error;
     * paid confirmation happens only via the webhook. (Stripe mocked)
     *
     * **Validates: Requirements 12.8**
     */
    // Feature: event-ticketing-platform, Property 22: Direct-charge failure leaves order unpaid — failed direct charge leaves Order unpaid, transfers no funds, surfaces an error (Stripe mocked)
    public function test_direct_charge_failure_never_leaves_order_paid(): void
    {
        $this->forAll(
            // 1–4 line items, each a per-type [priceMinor, quantity]. Prices are
            // strictly positive so the order is always PAID (subtotal > 0).
            Generator\seq(
                Generator\tuple(
                    Generator\choose(1, 50_000),   // price_minor (positive => paid)
                    Generator\choose(1, 8)         // quantity
                )
            ),
            // Fee mode + effective percent so the money snapshot varies.
            Generator\elements(...Company::FEE_MODES),
            Generator\map(
                fn (int $hundredths): string => number_format($hundredths / 100, 2, '.', ''),
                Generator\choose(0, 5_000)
            ),
            // Failure scenario: 0 = Customer cancels (visits cancel return);
            // 1 = Stripe Checkout Session creation fails at the boundary.
            Generator\choose(0, 1)
        )
            ->then(function (
                array $lineItems,
                string $feeMode,
                string $feePercent,
                int $scenario
            ): void {
                // Eris\seq can yield an empty sequence; ensure at least one line.
                if ($lineItems === []) {
                    $lineItems = [[1_000, 1]];
                }
                // Cap at 4 line items to keep each iteration cheap.
                $lineItems = array_slice($lineItems, 0, 4);

                [$company, $event, $types] = $this->scenario($feeMode, $feePercent, $lineItems);

                $items = [];
                $quantities = [];
                foreach ($types as $i => $type) {
                    $qty = $lineItems[$i][1];
                    $items[] = ['ticket_type_id' => $type->id, 'quantity' => $qty];
                    $quantities[$type->id] = $qty;
                }

                $payload = [
                    'customer_name' => 'Ada Lovelace',
                    'customer_email' => 'ada@example.test',
                    'items' => $items,
                    'consents' => ['terms' => true, 'privacy' => true],
                ];

                // The fake is a shared singleton across Eris iterations, so
                // reset its recorded calls to observe only THIS iteration.
                $fake = $this->fakeStripe();
                $fake->checkoutSessionCalls = [];
                $fake->refundCalls = [];

                if ($scenario === 1) {
                    // Arrange a session-creation failure BEFORE the checkout POST
                    // so the direct charge fails at the boundary. (Requirement 12.8)
                    $fake->failNextCheckoutSession();
                }

                // The controller reserves capacity, snapshots the money and
                // creates the Order (reserved) before it hands off to Stripe, so
                // a boundary failure surfaces as a 500 (the persisted Order stays
                // reserved). We tolerate the error rather than asserting a 2xx.
                $this->withoutExceptionHandling();

                $threw = false;
                try {
                    $this->post("/{$company->slug}/{$event->id}/checkout", $payload);
                } catch (\Throwable $e) {
                    // A failed direct charge surfaces an error to the Customer —
                    // that is the not-completed indication. (Requirement 12.8)
                    $threw = true;
                }

                // An Order was persisted (reserved) before the Stripe hand-off.
                $order = Order::withoutGlobalScopes()
                    ->where('event_id', $event->id)
                    ->firstOrFail();

                if ($scenario === 1) {
                    // Session-creation failure: the boundary threw, so NO charge
                    // was created (no funds move) and NO session id was stored.
                    // (Requirement 12.8)
                    $this->assertTrue($threw, 'A failed direct charge must surface an error.');
                    $this->assertCount(
                        0,
                        $fake->checkoutSessionCalls,
                        'A failed Checkout Session must record no successful charge (no funds move).'
                    );
                    $this->assertNull(
                        $order->fresh()->stripe_session_id,
                        'No Stripe session id is stored when session creation fails.'
                    );

                    // Core invariant: the Order is NEVER left paid. Paid state is
                    // only reachable via the webhook, which never ran. It remains
                    // reserved (awaiting), never `paid`. (Requirement 12.8)
                    $this->assertNotSame(
                        Order::STATUS_PAID,
                        $order->fresh()->status,
                        'A failed direct charge must never leave the Order paid.'
                    );
                    $this->assertSame(
                        Order::STATUS_RESERVED,
                        $order->fresh()->status,
                        'A failed direct charge leaves the Order reserved (awaiting), not paid.'
                    );
                } else {
                    // Customer-cancel path: checkout succeeded and redirected to
                    // hosted Checkout; the Order is reserved and still not paid.
                    $this->assertFalse($threw, 'A normal paid checkout must not error.');
                    $this->assertSame(
                        Order::STATUS_RESERVED,
                        $order->fresh()->status,
                        'Before the webhook, a paid Order is reserved, never paid.'
                    );

                    // Held capacity reflects the reservation. (Requirement 10.6)
                    foreach ($quantities as $typeId => $qty) {
                        $this->assertSame(
                            $qty,
                            (int) TicketType::withoutGlobalScopes()->find($typeId)->reserved_count,
                            'Reserved capacity must match the requested quantity before cancel.'
                        );
                    }

                    // The Customer abandons/cancels the hosted Checkout: the
                    // display-only cancel return is the failed-payment path — it
                    // releases capacity and cancels the Order. (Requirements
                    // 10.12, 12.8)
                    $this->get(route('checkout.cancel', [
                        'companySlug' => $company->slug,
                        'event' => $event->id,
                        'order' => $order->order_reference,
                    ]))->assertOk()->assertSee('not completed');

                    // Core invariant: the Order is NEVER paid; it is cancelled,
                    // no funds moved, and its held capacity was released.
                    // (Requirement 12.8)
                    $this->assertNotSame(
                        Order::STATUS_PAID,
                        $order->fresh()->status,
                        'A cancelled direct charge must never leave the Order paid.'
                    );
                    $this->assertSame(
                        Order::STATUS_CANCELLED,
                        $order->fresh()->status,
                        'The cancel path leaves the Order cancelled.'
                    );

                    foreach ($quantities as $typeId => $qty) {
                        $this->assertSame(
                            0,
                            (int) TicketType::withoutGlobalScopes()->find($typeId)->reserved_count,
                            'Cancel must release the held capacity back to available.'
                        );
                    }
                }

                // No refund was ever issued — a failed charge moves no funds, so
                // there is nothing to refund. (Requirement 12.8)
                $this->assertCount(
                    0,
                    $fake->refundCalls,
                    'A failed/cancelled direct charge moves no funds and issues no refund.'
                );
            });
    }

    /**
     * A published Event for a connected, charges-enabled Company under the given
     * Fee_Handling_Mode + percent, with one on-sale PAID Ticket_Type per line
     * item (its `price_minor` taken from the generated cart). Ample capacity so
     * every generated quantity reserves cleanly.
     *
     * @param  list<array{0:int,1:int}>  $lineItems
     * @return array{0: Company, 1: Event, 2: list<TicketType>}
     */
    private function scenario(string $feeMode, string $feePercent, array $lineItems): array
    {
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_test123',
            'stripe_charges_enabled' => true,
            'fee_handling_mode' => $feeMode,
            'company_fee_percent' => $feePercent,
            'currency' => 'gbp',
        ]);

        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

        $types = [];
        foreach ($lineItems as [$priceMinor, $qty]) {
            $types[] = TicketType::factory()->forEvent($event)->create([
                'price_minor' => $priceMinor,
                'capacity' => 1_000,
                'sold_count' => 0,
                'reserved_count' => 0,
                'sale_starts_at' => Carbon::now()->subDay(),
                'sale_ends_at' => Carbon::now()->addMonth(),
            ]);
        }

        return [$company, $event, $types];
    }
}
