<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature: event-experience split checkout.
 *
 * The public Event page now handles discovery + ticket selection only. Choosing
 * quantities and pressing "Continue" POSTs the selection to the dedicated
 * checkout step (CheckoutController::review), which renders the checkout page
 * with an order summary and the customer/consent/payment form. It creates no
 * Order — that only happens when the checkout form itself is submitted to the
 * order-creation endpoint.
 */
class CheckoutReviewStepTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: Company, 1: Event, 2: TicketType}
     */
    private function scenario(array $companyOverrides = [], array $typeOverrides = []): array
    {
        $company = Company::factory()->create(array_merge([
            'stripe_account_id' => 'acct_test123',
            'stripe_charges_enabled' => true,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'company_fee_percent' => '10.00',
        ], $companyOverrides));

        $event = Event::factory()->for($company)->published()->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create(array_merge([
            'name' => 'General Admission',
            'price_minor' => 2_000,
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ], $typeOverrides));

        return [$company, $event, $type];
    }

    public function test_event_page_no_longer_collects_customer_details_inline(): void
    {
        [$company, $event] = $this->scenario();

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        // Ticket selection posts to the review step, not straight to order creation.
        $response->assertSee(route('event.checkout.show', ['companySlug' => $company->slug, 'event' => $event->id]), false);
        // Customer/consent fields have moved to the dedicated checkout step.
        $response->assertDontSee('name="customer_name"', false);
        $response->assertDontSee('name="customer_email"', false);
        $response->assertSee('Continue');
    }

    public function test_review_renders_checkout_page_with_selected_tickets_and_creates_no_order(): void
    {
        [$company, $event, $type] = $this->scenario();

        $response = $this->post("/{$company->slug}/{$event->id}/checkout/review", [
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 2],
            ],
        ]);

        $response->assertOk();
        // The checkout step now collects the customer details + consents.
        $response->assertSee('name="customer_name"', false);
        $response->assertSee('name="customer_email"', false);
        // Order summary reflects the selected tickets and total (2 × £20.00).
        $response->assertSee('General Admission');
        $response->assertSee('£40.00');
        // The final form posts to the order-creation endpoint.
        $response->assertSee(route('event.checkout', ['companySlug' => $company->slug, 'event' => $event->id]), false);

        // No Order is created merely by reaching the checkout page.
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_review_drops_zero_quantity_lines_and_keeps_indices_contiguous(): void
    {
        [$company, $event, $type] = $this->scenario();
        $other = TicketType::factory()->forEvent($event)->create([
            'name' => 'VIP',
            'price_minor' => 5_000,
            'capacity' => 100,
            'sold_count' => 0,
            'reserved_count' => 0,
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ]);

        $response = $this->post("/{$company->slug}/{$event->id}/checkout/review", [
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 0],
                ['ticket_type_id' => $other->id, 'quantity' => 1],
            ],
        ]);

        $response->assertOk();
        $response->assertSee('VIP');
        // Carried-forward hidden inputs start at index 0 even though the first
        // submitted line (quantity 0) was dropped.
        $response->assertSee('name="items[0][ticket_type_id]" value="'.$other->id.'"', false);
    }

    public function test_review_with_empty_selection_redirects_back_to_the_event_page(): void
    {
        [$company, $event, $type] = $this->scenario();

        $response = $this->post("/{$company->slug}/{$event->id}/checkout/review", [
            'items' => [
                ['ticket_type_id' => $type->id, 'quantity' => 0],
            ],
        ]);

        $response->assertRedirect(route('event.page', ['companySlug' => $company->slug, 'event' => $event->id]));
        $response->assertSessionHas('checkout_error');
    }

    public function test_review_on_unpublished_event_returns_404(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->unpublished()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ]);

        $this->post("/{$company->slug}/{$event->id}/checkout/review", [
            'items' => [['ticket_type_id' => $type->id, 'quantity' => 1]],
        ])->assertNotFound();
    }
}
