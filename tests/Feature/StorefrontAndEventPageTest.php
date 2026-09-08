<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use App\Services\StorefrontListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 12.1 — the public Company Storefront (StorefrontController) and
 * public Event page (EventPageController), with the cached storefront listing
 * and its invalidation.
 *
 * Requirements:
 *  - 8.2 storefront lists the Company's published events
 *  - 8.3 public event page renders ticket types + availability + sale state
 *  - 8.4/8.5 branding applied to storefront and event page
 *  - 5.4 published events are visible; 5.5 unpublished excluded / event 404
 *  - 6.10 remaining availability = capacity - sold_count - reserved_count
 *  - 7.1/7.2 resolved logo + primary colour applied
 */
class StorefrontAndEventPageTest extends TestCase
{
    use RefreshDatabase;

    // ---- Storefront listing --------------------------------------------------

    public function test_storefront_lists_only_published_events(): void
    {
        // Requirements 8.2, 5.4, 5.5 — published events listed, drafts excluded.
        $company = Company::factory()->create();
        Event::factory()->for($company)->published()->create(['name' => 'Live Show']);
        Event::factory()->for($company)->unpublished()->create(['name' => 'Draft Show']);

        $response = $this->get("/{$company->slug}");

        $response->assertOk();
        $response->assertSee('Live Show');
        $response->assertDontSee('Draft Show');
    }

    public function test_storefront_excludes_other_companies_events(): void
    {
        // Requirement 1.5 — only the resolved Company's events appear.
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        Event::factory()->for($company)->published()->create(['name' => 'Mine Live']);
        Event::factory()->for($other)->published()->create(['name' => 'Theirs Live']);

        $response = $this->get("/{$company->slug}");

        $response->assertOk();
        $response->assertSee('Mine Live');
        $response->assertDontSee('Theirs Live');
    }

    public function test_storefront_applies_company_branding(): void
    {
        // Requirements 7.1, 7.2, 8.4 — logo path + primary colour applied.
        $company = Company::factory()->create([
            'primary_colour' => '#AB12CD',
            'logo_path' => 'branding/logos/co.png',
        ]);
        Event::factory()->for($company)->published()->create();

        $response = $this->get("/{$company->slug}");

        $response->assertOk();
        $response->assertSee('#AB12CD');
        $response->assertSee('branding/logos/co.png');
    }

    // ---- Listing cache invalidation ------------------------------------------

    public function test_publishing_an_event_refreshes_the_storefront_listing(): void
    {
        // Requirement 8.2 — the cached listing is invalidated on publish, so a
        // newly published event appears on the next storefront request.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        // Warm the cache while there is nothing published.
        $this->get("/{$company->slug}")->assertOk()->assertDontSee('Fresh Event');

        $event = Event::factory()->for($company)->unpublished()->create([
            'name' => 'Fresh Event',
            'starts_at' => now()->addWeek(),
        ]);

        // The event must satisfy publish prerequisites (>=1 ticket type and a
        // non-null starts_at) so publish is not a no-op. A FREE ticket type
        // also keeps the payments gate satisfied without connecting Stripe.
        TicketType::factory()->forEvent($event)->free()->create();

        // Publishing through the dashboard must invalidate the cached listing.
        $this->actingAs($admin)->post("/dashboard/events/{$event->id}/publish")->assertRedirect();

        $this->get("/{$company->slug}")->assertOk()->assertSee('Fresh Event');
    }

    public function test_unpublishing_an_event_removes_it_from_the_listing(): void
    {
        // Requirement 5.5 — unpublishing invalidates the cache and drops the
        // event from the public listing.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $event = Event::factory()->for($company)->published()->create(['name' => 'Going Dark']);

        // Warm the cache while published.
        $this->get("/{$company->slug}")->assertOk()->assertSee('Going Dark');

        $this->actingAs($admin)->post("/dashboard/events/{$event->id}/unpublish")->assertRedirect();

        $this->get("/{$company->slug}")->assertOk()->assertDontSee('Going Dark');
    }

    public function test_updating_a_published_event_refreshes_the_listing(): void
    {
        // Requirement 8.2 — updated details show on the storefront after update.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $event = Event::factory()->for($company)->published()->create(['name' => 'Old Title']);

        $this->get("/{$company->slug}")->assertOk()->assertSee('Old Title');

        $this->actingAs($admin)->put("/dashboard/events/{$event->id}", [
            'name' => 'New Title',
            'location_mode' => Event::LOCATION_IN_PERSON,
        ])->assertRedirect();

        $this->get("/{$company->slug}")->assertOk()->assertSee('New Title')->assertDontSee('Old Title');
    }

    public function test_listing_service_forget_rebuilds_from_database(): void
    {
        // The listing service caches and rebuilds on forget.
        $company = Company::factory()->create();
        $listing = app(StorefrontListing::class);

        $this->assertCount(0, $listing->forCompany($company));

        Event::factory()->for($company)->published()->create(['name' => 'Later']);

        // Stale cache still returns the empty listing until invalidated.
        $this->assertCount(0, $listing->forCompany($company));

        $listing->forget($company);

        $this->assertCount(1, $listing->forCompany($company));
    }

    // ---- Event page ----------------------------------------------------------

    public function test_published_event_page_renders_ticket_types_without_exposing_counts(): void
    {
        // The storefront must not expose exact inventory counts to Customers.
        // 100 - 30 - 20 = 50 remaining (50% of capacity) => healthy stock, so
        // the page shows "Available" and never the raw remaining number.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create(['name' => 'Concert']);
        TicketType::factory()->forEvent($event)->create([
            'name' => 'General Admission',
            'capacity' => 100,
            'sold_count' => 30,
            'reserved_count' => 20,
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addDay(),
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Concert');
        $response->assertSee('General Admission');
        // Healthy stock within the sale window renders "On sale" with no count.
        $response->assertSee('On sale');
        $response->assertDontSee('Limited availability');
        // Never leak the exact remaining count to Customers.
        $response->assertDontSee('50 remaining');
        $response->assertDontSee('remaining');
    }

    public function test_event_page_shows_limited_availability_when_stock_is_low(): void
    {
        // 100 capacity, 90 sold => 10 remaining (10% <= 20% threshold), so the
        // page shows "Limited availability" without revealing the count.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        TicketType::factory()->forEvent($event)->create([
            'name' => 'Nearly Gone',
            'capacity' => 100,
            'sold_count' => 90,
            'reserved_count' => 0,
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addDay(),
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Limited availability');
        $response->assertDontSee('10 remaining');
    }

    public function test_event_page_shows_sold_out_when_no_availability(): void
    {
        // Requirement 6.10 — zero remaining renders as sold out.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        TicketType::factory()->forEvent($event)->create([
            'name' => 'VIP',
            'capacity' => 10,
            'sold_count' => 10,
            'reserved_count' => 0,
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Sold out');
    }

    public function test_event_page_shows_on_sale_state_within_the_sale_window(): void
    {
        // Sale window open now => on sale.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        TicketType::factory()->forEvent($event)->create([
            'name' => 'Open Now',
            'sale_starts_at' => Carbon::now()->subDay(),
            'sale_ends_at' => Carbon::now()->addDay(),
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('On sale');
    }

    public function test_event_page_shows_not_yet_on_sale_before_the_window(): void
    {
        // Requirement 6.4 — before the window opens => not yet on sale.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        TicketType::factory()->forEvent($event)->create([
            'name' => 'Future Sale',
            'sale_starts_at' => Carbon::now()->addWeek(),
            'sale_ends_at' => Carbon::now()->addMonth(),
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Not yet on sale');
    }

    public function test_event_page_shows_sale_ended_after_the_window(): void
    {
        // Requirement 6.5 — at/after the window close => sale ended.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        TicketType::factory()->forEvent($event)->create([
            'name' => 'Past Sale',
            'sale_starts_at' => Carbon::now()->subMonth(),
            'sale_ends_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('Sale ended');
    }

    public function test_event_page_applies_event_branding_override(): void
    {
        // Requirements 7.2, 7.5, 8.5 — event override colour wins over company.
        $company = Company::factory()->create(['primary_colour' => '#111111']);
        $event = Event::factory()->for($company)->published()->create([
            'primary_colour' => '#EE00FF',
        ]);

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertSee('#EE00FF');
        $response->assertDontSee('#111111');
    }

    public function test_unpublished_event_page_returns_404(): void
    {
        // Requirements 5.4, 5.5 — only published events are visible.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->unpublished()->create();

        $this->get("/{$company->slug}/{$event->id}")->assertNotFound();
    }

    // ---- Page chrome ---------------------------------------------------------

    public function test_event_page_hides_the_marketing_header_and_full_footer(): void
    {
        // The public event page should match the storefront/checkout chrome:
        // no marketing header (Log in / Sign up) and only the slim promo footer,
        // not the full site footer with its platform/legal/contact columns.
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        $response->assertDontSee('public-header', false);
        $response->assertDontSee('site-footer', false);
        // The slim promo footer is present instead.
        $response->assertSee('promo-footer', false);
    }

    // ---- Support & lost tickets resend ---------------------------------------

    public function test_event_page_shows_support_and_lost_tickets_section(): void
    {
        $company = Company::factory()->create([
            'support_email' => 'help@organiser.test',
            'phone' => '+44 20 7946 0000',
        ]);
        $event = Event::factory()->for($company)->published()->create();

        $response = $this->get("/{$company->slug}/{$event->id}");

        $response->assertOk();
        // Softer, reassuring wording moved to the bottom of the page.
        $response->assertSee('Already booked?');
        $response->assertSee('Retrieve or resend your tickets');
        $response->assertSee('help@organiser.test');
    }

    public function test_resend_queues_ticket_emails_for_matching_confirmed_orders(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();

        // Two confirmed orders (paid + free) for the same email should both be
        // re-queued; the match is case-insensitive on the stored email.
        Order::factory()->forEvent($event)->create([
            'customer_email' => 'buyer@example.test',
            'status' => Order::STATUS_PAID,
        ]);
        Order::factory()->forEvent($event)->free()->create([
            'customer_email' => 'BUYER@example.test',
            'status' => Order::STATUS_FREE_CONFIRMED,
        ]);

        $response = $this->post("/{$company->slug}/{$event->id}/resend", [
            'email' => 'buyer@example.test',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('resend_status');
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\SendTicketEmailJob::class, 2);
    }

    public function test_resend_does_not_queue_for_unmatched_email_but_still_confirms(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        Order::factory()->forEvent($event)->create([
            'customer_email' => 'someone@example.test',
            'status' => Order::STATUS_PAID,
        ]);

        $response = $this->post("/{$company->slug}/{$event->id}/resend", [
            'email' => 'nobody@example.test',
        ]);

        // Same neutral outcome as a match — the endpoint never reveals whether
        // the email has a booking.
        $response->assertRedirect();
        $response->assertSessionHas('resend_status');
        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_resend_ignores_unconfirmed_orders(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        // Reserved (unconfirmed) and cancelled orders must not be resent.
        Order::factory()->forEvent($event)->create([
            'customer_email' => 'pending@example.test',
            'status' => Order::STATUS_RESERVED,
        ]);
        Order::factory()->forEvent($event)->create([
            'customer_email' => 'pending@example.test',
            'status' => Order::STATUS_CANCELLED,
        ]);

        $this->post("/{$company->slug}/{$event->id}/resend", [
            'email' => 'pending@example.test',
        ])->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_resend_does_not_cross_event_or_company_boundaries(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();
        $otherEvent = Event::factory()->for($company)->published()->create();

        // Confirmed order for the SAME email but a DIFFERENT event must not be
        // resent when requesting from $event's page.
        Order::factory()->forEvent($otherEvent)->create([
            'customer_email' => 'buyer@example.test',
            'status' => Order::STATUS_PAID,
        ]);

        $this->post("/{$company->slug}/{$event->id}/resend", [
            'email' => 'buyer@example.test',
        ])->assertRedirect();

        \Illuminate\Support\Facades\Queue::assertNothingPushed();
    }

    public function test_resend_validates_the_email(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->published()->create();

        $this->post("/{$company->slug}/{$event->id}/resend", [
            'email' => 'not-an-email',
        ])->assertSessionHasErrors('email');
    }

    public function test_resend_on_unpublished_event_returns_404(): void
    {
        $company = Company::factory()->create();
        $event = Event::factory()->for($company)->unpublished()->create();

        $this->post("/{$company->slug}/{$event->id}/resend", [
            'email' => 'buyer@example.test',
        ])->assertNotFound();
    }
}
