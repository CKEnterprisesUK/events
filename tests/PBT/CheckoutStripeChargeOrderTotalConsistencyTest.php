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
 * Property-based test that COMPOSES Property 17 (Order_Total consistency across
 * the two Fee_Handling_Modes) against the STRIPE MOCK, driving the real HTTP
 * checkout endpoint end-to-end. (task 15.3)
 *
 * Property 17 was proven at the pure fee-engine level in
 * {@see OrderTotalConsistencyTest} (task 9.4). Here we reuse the SAME
 * assertion, but now measured on the amount the Platform actually asks Stripe
 * to charge: the recorded Checkout Session call on the connected account. This
 * proves the invariant survives the whole checkout + Stripe-mock path, not just
 * the pure engine.
 *
 * For a paid cart the checkout creates a Stripe Checkout Session as a DIRECT
 * CHARGE on the Company's connected account with `amount = Order_Total` and
 * `application_fee_amount = Application_Fee`. The container-bound
 * {@see FakeStripePaymentService} records that call (amount_minor,
 * application_fee_minor, connected_account_id, currency) — no live Stripe call
 * and no card data. We assert, across BOTH fee modes and random fee
 * percents/prices/quantities:
 * - the charged `amount_minor` == the Order's `order_total_minor`, AND
 * - the charged `amount_minor` == the expected Order_Total for that mode
 *   (Absorb: Order_Total = subtotal, Booking_Fee = 0;
 *    Pass_On: Booking_Fee = fee, Order_Total = subtotal + fee), AND
 * - the charged `application_fee_minor` == the Order's `application_fee_minor`,
 *   on the correct connected account and currency.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Each iteration provisions a
 * fresh Company/Event/Ticket_Type and scopes every assertion to that
 * iteration's Order, so iterations stay independent without any DDL or
 * table-wide deletes (which could deadlock with the reservation FOR UPDATE).
 *
 * Runs against the real MySQL test DB so the FOR UPDATE reservation behaves as
 * in production.
 */
class CheckoutStripeChargeOrderTotalConsistencyTest extends PbtTestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * Property 17 (composed against the Stripe mock): across both fee modes the
     * amount the Platform charges via the Stripe Checkout Session (a direct
     * charge on the connected account) equals the Order_Total and the
     * `application_fee_amount` equals the Application_Fee — Absorb:
     * Order_Total = subtotal, Booking_Fee = 0; Pass_On: Booking_Fee = fee,
     * Order_Total = subtotal + fee.
     *
     * **Validates: Requirements 12.1, 12.2 (Stripe boundary)**
     */
    // Feature: event-ticketing-platform, Property 17: Order_Total consistency across fee modes — Absorb: Order_Total = subtotal, Booking_Fee = 0; Pass_On: Booking_Fee = fee, Order_Total = subtotal + fee; direct charge amount = Order_Total
    public function test_stripe_charge_amount_equals_order_total_across_fee_modes(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Fee handling mode: Absorb vs Pass_On.
            Generator\elements(...Company::FEE_MODES),
            // Effective fee percent as hundredths-of-a-percent (0.00%–100.00%),
            // scaled back to a DECIMAL(5,2)-style value below.
            Generator\choose(0, 10_000),
            // Unit price in minor units — kept paid (>= 1) so the cart routes
            // through the Stripe direct-charge path rather than the free path.
            Generator\choose(1, 500_000),
            // Quantity of that Ticket_Type in the cart.
            Generator\choose(1, 10),
        )
            ->then(function (string $mode, int $percentHundredths, int $priceMinor, int $qty): void {
                $percent = number_format($percentHundredths / 100, 2, '.', '');

                // Fresh, charges-enabled Company on a connected account, a
                // published Event with unlimited capacity, and an on-sale paid
                // Ticket_Type — provisioned per iteration for independence.
                $accountId = 'acct_'.bin2hex(random_bytes(6));

                $company = Company::factory()->create([
                    'stripe_account_id' => $accountId,
                    'stripe_charges_enabled' => true,
                    'fee_handling_mode' => $mode,
                    'company_fee_percent' => $percent,
                    'currency' => 'gbp',
                ]);

                $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

                $type = TicketType::factory()->forEvent($event)->create([
                    'price_minor' => $priceMinor,
                    'capacity' => 1_000,
                    'sold_count' => 0,
                    'reserved_count' => 0,
                    'sale_starts_at' => Carbon::now()->subDay(),
                    'sale_ends_at' => Carbon::now()->addMonth(),
                ]);

                $subtotal = $priceMinor * $qty;

                // Drive the real HTTP checkout endpoint with a valid
                // customer + accepted required consents. A paid cart is
                // redirected to hosted Checkout.
                $response = $this->post("/{$company->slug}/{$event->id}/checkout", [
                    'customer_name' => 'Ada Lovelace',
                    'customer_email' => 'ada@example.test',
                    'items' => [
                        ['ticket_type_id' => $type->id, 'quantity' => $qty],
                    ],
                    'consents' => ['terms' => true, 'privacy' => true],
                ]);

                $response->assertRedirect();

                // Scope to THIS iteration's Order (by event) — never a
                // table-wide read that could span other iterations.
                $order = Order::withoutGlobalScopes()
                    ->where('event_id', $event->id)
                    ->firstOrFail();

                // The recorded Stripe Checkout Session call for this iteration.
                $calls = $this->fakeStripe()->checkoutSessionCalls;
                $this->assertNotEmpty($calls, 'Paid checkout must record a Stripe Checkout Session call.');
                $call = end($calls);

                // Direct charge on the Company's CONNECTED account, in the
                // Company currency. (Requirement 12.1)
                $this->assertSame($accountId, $call['connected_account_id'], 'Charge must be on the connected account.');
                $this->assertSame('gbp', $call['currency'], 'Charge currency must be the Company currency.');

                // --- Property 17, composed against the mock ------------------

                // (a) Charged amount == the Order's persisted Order_Total.
                $this->assertSame(
                    $order->order_total_minor,
                    $call['amount_minor'],
                    'Charged amount must equal the Order order_total_minor.'
                );

                // (b) Charged amount == the expected Order_Total for the mode.
                if ($mode === Company::FEE_MODE_ABSORB) {
                    // Absorb: Order_Total = subtotal, Booking_Fee = 0.
                    $this->assertSame(
                        $subtotal,
                        $call['amount_minor'],
                        sprintf('Absorb: charged amount must equal subtotal (%d, %s%%, qty %d).', $subtotal, $percent, $qty)
                    );
                    $this->assertSame(
                        0,
                        $order->booking_fee_minor,
                        sprintf('Absorb: Booking_Fee must be zero (%d, %s%%).', $subtotal, $percent)
                    );
                } else {
                    // Pass_On: Booking_Fee = Application_Fee,
                    // Order_Total = subtotal + Booking_Fee.
                    $this->assertSame(
                        $order->application_fee_minor,
                        $order->booking_fee_minor,
                        sprintf('Pass_On: Booking_Fee must equal Application_Fee (%d, %s%%).', $subtotal, $percent)
                    );
                    $this->assertSame(
                        $subtotal + $order->booking_fee_minor,
                        $call['amount_minor'],
                        sprintf('Pass_On: charged amount must equal subtotal + Booking_Fee (%d, %s%%, qty %d).', $subtotal, $percent, $qty)
                    );
                }

                // (c) application_fee_amount == the Order's Application_Fee.
                // (Requirements 12.1, 12.2)
                $this->assertSame(
                    $order->application_fee_minor,
                    $call['application_fee_minor'],
                    'application_fee_amount must equal the Order application_fee_minor.'
                );
            });
    }
}
