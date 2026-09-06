<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 12.2 — CheckoutController order creation up to `reserved` status:
 * cart validation, capacity reservation (900s window), money + fee-mode
 * snapshot, consent capture, one Ticket per purchased ticket, and gating on
 * publish / sale window / capacity / charges-enabled.
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.8, 10.13, 22.4, 5.5,
 * 6.4/6.5, 13.4/13.5/13.8.
 *
 * Runs against the real MySQL test DB so the FOR UPDATE reservation behaves as
 * in production.
 */
class CheckoutOrderCreationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A published Event for $company with an on-sale paid Ticket_Type.
     *
     * @return array{0: Company, 1: Event, 2: TicketType}
     */
    private function scenario(array $companyOverrides = [], array $typeOverrides = []): array
    {
        $company = Company::factory()->create(array_merge([
            // Connected + charges enabled so paid checkout is permitted by default.
            'stripe_account_id' => 'acct_test123',
            'stripe_charges_enabled' => true,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'company_fee_percent' => '10.00',
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

    // ---- Happy path: reserve + snapshot + consents + tickets ----------------

    public function test_valid_checkout_creates_reserved_order_with_tickets_consents_and_snapshot(): void
    {
        [$company, $event, $type] = $this->scenario();

        $response = $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 3));

        $response->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // Reserved status + 900s window. (Requirements 10.6, 10.7)
        $this->assertSame(Order::STATUS_RESERVED, $order->status);
        $this->assertNotNull($order->reserved_until);
        $this->assertEqualsWithDelta(
            now()->addSeconds(CapacityReservationService::RESERVATION_WINDOW_SECONDS)->timestamp,
            $order->reserved_until->timestamp,
            5
        );

        // Customer recorded. (Requirement 10.1)
        $this->assertSame('Ada Lovelace', $order->customer_name);
        $this->assertSame('ada@example.test', $order->customer_email);

        // Money snapshot: subtotal 3 × 2000 = 6000; 10% fee = 600; absorb =>
        // booking 0, total = subtotal. (Requirements 12.2, 13.4, 13.8)
        $this->assertSame(6_000, $order->ticket_subtotal_minor);
        $this->assertSame(600, $order->application_fee_minor);
        $this->assertSame(0, $order->booking_fee_minor);
        $this->assertSame(6_000, $order->order_total_minor);
        $this->assertSame(Company::FEE_MODE_ABSORB, $order->fee_handling_mode);

        // One Ticket per purchased ticket. (Requirement 10.5)
        $this->assertSame(3, Ticket::withoutGlobalScopes()->where('order_id', $order->id)->count());

        // Consents stored as submitted. (Requirements 10.3, 22.4)
        $consents = OrderConsent::withoutGlobalScopes()->where('order_id', $order->id)->pluck('accepted', 'consent_key');
        $this->assertTrue((bool) $consents['terms']);
        $this->assertTrue((bool) $consents['privacy']);

        // Capacity was held. (Requirement 10.6)
        $this->assertSame(3, $type->fresh()->reserved_count);

        // Order_Reference assigned. (Requirement 10.13)
        $this->assertNotEmpty($order->order_reference);
    }

    public function test_pass_on_mode_adds_booking_fee_to_total(): void
    {
        [$company, $event, $type] = $this->scenario([
            'fee_handling_mode' => Company::FEE_MODE_PASS_ON,
            'company_fee_percent' => '10.00',
        ]);

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 2))
            ->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();

        // subtotal 4000, 10% fee 400, pass_on => booking 400, total 4400.
        // (Requirement 13.5)
        $this->assertSame(4_000, $order->ticket_subtotal_minor);
        $this->assertSame(400, $order->application_fee_minor);
        $this->assertSame(400, $order->booking_fee_minor);
        $this->assertSame(4_400, $order->order_total_minor);
        $this->assertSame(Company::FEE_MODE_PASS_ON, $order->fee_handling_mode);
    }

    // ---- Cart validation ----------------------------------------------------

    public function test_missing_name_rejects_checkout_and_creates_no_order(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1, [
            'customer_name' => '',
        ]))->assertSessionHasErrors('customer_name');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    public function test_invalid_email_rejects_checkout_and_creates_no_order(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1, [
            'customer_email' => 'not-an-email',
        ]))->assertSessionHasErrors('customer_email');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_missing_required_consent_rejects_checkout_and_creates_no_order(): void
    {
        [$company, $event, $type] = $this->scenario();

        // privacy not accepted. (Requirements 10.4, 22.4)
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1, [
            'consents' => ['terms' => true, 'privacy' => false],
        ]))->assertSessionHasErrors('consents.privacy');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    public function test_ticket_type_from_another_event_is_rejected(): void
    {
        [$company, $event, $type] = $this->scenario();
        // A ticket type on a different event of the same company.
        $otherEvent = Event::factory()->for($company)->published()->create();
        $foreign = TicketType::factory()->forEvent($otherEvent)->create();

        $this->post("/{$company->slug}/{$event->id}/checkout", [
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.test',
            'items' => [['ticket_type_id' => $foreign->id, 'quantity' => 1]],
            'consents' => ['terms' => true, 'privacy' => true],
        ])->assertSessionHasErrors('items');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    // ---- Oversell rejection --------------------------------------------------

    public function test_oversell_request_is_rejected_cleanly_without_creating_an_order(): void
    {
        [$company, $event, $type] = $this->scenario([], [
            'capacity' => 5,
            'sold_count' => 4, // only 1 remaining
        ]);

        // Requirement 10.6 — requesting 2 when 1 remains is rejected whole.
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 2))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        // Nothing reserved. (Requirement 10.6)
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    // ---- Publish / sale-window gating ---------------------------------------

    public function test_unpublished_event_checkout_returns_404(): void
    {
        [$company, $event, $type] = $this->scenario();
        $event->unpublish();

        // Requirement 5.5 — unpublished events block purchase.
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1))
            ->assertNotFound();

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_out_of_sale_window_ticket_type_is_rejected(): void
    {
        [$company, $event, $type] = $this->scenario([], [
            'sale_starts_at' => Carbon::now()->addWeek(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ]);

        // Requirement 6.4 — before the window opens, purchase is rejected.
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    // ---- Charges-enabled gating (paid vs free) ------------------------------

    public function test_paid_checkout_blocked_when_charges_not_enabled(): void
    {
        [$company, $event, $type] = $this->scenario([
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
        ]);

        // Requirement 10.8 — no connected charges-enabled account blocks paid.
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        // Reservation is released back after the persistence-guard is not even
        // reached (gate fails before reserve); nothing held.
        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    public function test_free_only_checkout_allowed_without_charges_enabled(): void
    {
        [$company, $event, $type] = $this->scenario([
            'stripe_account_id' => null,
            'stripe_charges_enabled' => false,
        ], [
            'price_minor' => 0, // free
        ]);

        // Requirement 10.9 — free-only orders are always permitted.
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 2))
            ->assertRedirect();

        $order = Order::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(0, $order->order_total_minor);
        $this->assertSame(0, $order->application_fee_minor);
        $this->assertSame(2, Ticket::withoutGlobalScopes()->where('order_id', $order->id)->count());
    }

    public function test_order_references_are_unique_across_orders(): void
    {
        [$company, $event, $type] = $this->scenario();

        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1))->assertRedirect();
        $this->post("/{$company->slug}/{$event->id}/checkout", $this->payload($type, 1))->assertRedirect();

        $refs = Order::withoutGlobalScopes()->pluck('order_reference');
        $this->assertCount(2, $refs);
        $this->assertCount(2, $refs->unique());
    }
}
