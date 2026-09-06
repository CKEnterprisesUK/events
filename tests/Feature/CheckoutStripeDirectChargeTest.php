<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 15.1 — Stripe Checkout as a DIRECT CHARGE with
 * `application_fee_amount`, the success/cancel return pages, and the free-order
 * confirmation path. Stripe is ALWAYS mocked via the container-bound
 * {@see FakeStripePaymentService}; no live call and no card data are involved.
 *
 * Requirements: 10.9, 10.10, 10.11, 10.12, 12.1, 12.5.
 *
 * Runs against the real MySQL test DB so the FOR UPDATE reservation/release
 * behaves as in production.
 */
class CheckoutStripeDirectChargeTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * A published Event for a connected, charges-enabled Company with an
     * on-sale paid Ticket_Type.
     *
     * @return array{0: Company, 1: Event, 2: TicketType}
     */
    private function scenario(array $companyOverrides = [], array $typeOverrides = []): array
    {
        $company = Company::factory()->create(array_merge([
            'stripe_account_id' => 'acct_test123',
            'stripe_charges_enabled' => true,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'company_fee_percent' => '10.00',
            'currency' => 'gbp',
        ], $companyOverrides));

        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create(array_merge([
            'price_minor' => 2_000,
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ], $typeOverrides));

        return [$company, $event, $type];
    }

    private function payload(TicketType $type, int $qty = 2, array $overrides = []): array
    {
        return array_merge([
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.test',
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => $qty],
            ],
            'consents' => ['terms' => true, 'privacy' => true],
        ], $overrides);
    }

    // ---- Paid order → direct charge with application_fee_amount -------------

    public function test_paid_checkout_creates_direct_charge_session_and_redirects_to_hosted_checkout(): void
    {
        [$company, $event, $type] = $this->scenario();

        // subtotal 3 × 2000 = 6000; absorb 10% fee => application 600, total 6000.
        $response = $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 3));

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // Redirected to the hosted Checkout URL returned by the fake.
        $calls = $this->fakeStripe()->checkoutSessionCalls;
        $this->assertCount(1, $calls);

        $call = $calls[0];

        // The Customer is sent to the hosted Checkout session for this id.
        $response->assertRedirect('https://checkout.stripe.test/session/'.$call['id']);

        // Direct charge on the Company's CONNECTED account. (Requirement 12.1)
        $this->assertSame('acct_test123', $call['connected_account_id']);

        // application_fee_amount == the Order's Application_Fee. (Requirements 12.1, 12.2)
        $this->assertSame($order->application_fee_minor, $call['application_fee_minor']);
        $this->assertSame(600, $call['application_fee_minor']);

        // Charge amount == Order_Total, in the Company currency. (Requirement 10.11, 12.1)
        $this->assertSame($order->order_total_minor, $call['amount_minor']);
        $this->assertSame(6_000, $call['amount_minor']);
        $this->assertSame('gbp', $call['currency']);

        // Session id persisted; Order stays reserved (paid only via webhook).
        $this->assertNotNull($order->stripe_session_id);
        $this->assertSame($call['id'], $order->stripe_session_id);
        $this->assertSame(Order::STATUS_RESERVED, $order->status);
    }

    public function test_pass_on_mode_charges_total_including_booking_fee_with_matching_application_fee(): void
    {
        [$company, $event, $type] = $this->scenario([
            'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
            'company_fee_percent' => '10.00',
        ]);

        // subtotal 4000; 10% fee 400; pass_on => booking 400, total 4400.
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 2))
            ->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $call = $this->fakeStripe()->checkoutSessionCalls[0];

        $this->assertSame(4_400, $call['amount_minor']);
        $this->assertSame(4_400, $order->order_total_minor);
        $this->assertSame(400, $call['application_fee_minor']);
        $this->assertSame($order->application_fee_minor, $call['application_fee_minor']);
    }

    // ---- Free order → confirmed immediately, no Stripe ----------------------

    public function test_free_only_order_confirms_immediately_without_calling_stripe(): void
    {
        [$company, $event, $type] = $this->scenario([
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
        ], [
            'price_minor' => 0,
        ]);

        $response = $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 2));

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // Confirmed immediately; no Stripe interaction. (Requirements 10.9, 12.5)
        $this->assertSame(Order::STATUS_FREE_CONFIRMED, $order->status);
        $this->assertSame(0, $order->order_total_minor);
        $this->assertNull($order->stripe_session_id);
        $this->assertCount(0, $this->fakeStripe()->checkoutSessionCalls);

        // Sent to the success return page.
        $response->assertRedirect(route('checkout.success', [
            'companySlug' => $company->slug,
            'event' => $event->id,
            'order' => $order->order_reference,
        ]));
    }

    // ---- Success return page (display-only) ---------------------------------

    public function test_success_page_shows_paid_order_as_awaiting_confirmation(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1));
        $order = Order::withoutGlobalScopes()->firstOrFail();

        $response = $this->get(route('checkout.success', [
            'companySlug' => $company->slug,
            'event' => $event->id,
            'order' => $order->order_reference,
        ]));

        $response->assertOk();
        $response->assertSee($order->order_reference);
        $response->assertSee('being confirmed');

        // Display-only: the return never marks the Order paid. (Requirement 12.6)
        $this->assertSame(Order::STATUS_RESERVED, $order->fresh()->status);
    }

    public function test_success_page_shows_free_order_as_confirmed(): void
    {
        [$company, $event, $type] = $this->scenario([], ['price_minor' => 0]);

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1));
        $order = Order::withoutGlobalScopes()->firstOrFail();

        $this->get(route('checkout.success', [
            'companySlug' => $company->slug,
            'event' => $event->id,
            'order' => $order->order_reference,
        ]))->assertOk()->assertSee('confirmed');

        $this->assertSame(Order::STATUS_FREE_CONFIRMED, $order->fresh()->status);
    }

    // ---- Cancel return page → release + cancel ------------------------------

    public function test_cancel_page_releases_reservation_and_cancels_the_order(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 3));
        $order = Order::withoutGlobalScopes()->firstOrFail();

        // Capacity held after reservation. (Requirement 10.6)
        $this->assertSame(3, $type->fresh()->reserved_count);

        $response = $this->get(route('checkout.cancel', [
            'companySlug' => $company->slug,
            'event' => $event->id,
            'order' => $order->order_reference,
        ]));

        // Payment-not-completed path: Order cancelled, capacity released.
        // (Requirement 10.12)
        $response->assertOk();
        $response->assertSee('not completed');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    public function test_cancel_page_is_idempotent_on_repeat_visits(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 2));
        $order = Order::withoutGlobalScopes()->firstOrFail();

        $url = route('checkout.cancel', [
            'companySlug' => $company->slug,
            'event' => $event->id,
            'order' => $order->order_reference,
        ]);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        // Never drives reserved_count negative; a second cancel is a no-op.
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    // ---- No card data anywhere ----------------------------------------------

    public function test_no_card_data_is_ever_recorded_by_the_stripe_boundary(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1));

        $recorded = json_encode($this->fakeStripe()->checkoutSessionCalls);

        // The boundary only ever sees amounts, currency, fee, and URLs — never
        // PAN/CVC/card fields. (Requirement 12.5 — PCI scope stays with Stripe)
        $this->assertStringNotContainsStringIgnoringCase('card', (string) $recorded);
        $this->assertStringNotContainsStringIgnoringCase('cvc', (string) $recorded);
        $this->assertStringNotContainsStringIgnoringCase('pan', (string) $recorded);
    }
}
