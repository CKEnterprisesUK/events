<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Http\Controllers\CustomerController;
use App\Services\GdprService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform — task 25.1.
 *
 * Covers the GDPR data-subject tools (now surfaced on the Customers page via
 * CustomerController), GdprService and the public privacy policy page:
 *   - 22.1 Export a Customer's stored personal data (incl. consents).
 *   - 22.2 Delete/anonymise a Customer's personal data while retaining the
 *          transactional records required for reconciliation.
 *   - 22.3 A public privacy policy page.
 *   - 22.4 Captured consents are surfaced in the export.
 *   - 22.5 Export/delete are scoped to the requesting Company.
 *   - 3.3/3.7 Gated on ACTION_MANAGE_GDPR (Owner + Admin); other roles denied.
 */
class GdprDataToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an Order for the given Company/Event and Customer email with the
     * given money snapshot, `$qty` valid tickets, and a couple of consents.
     */
    private function customerOrder(
        Event $event,
        string $email,
        string $name = 'Casey Customer',
        int $subtotal = 5_000,
        int $bookingFee = 300,
        int $applicationFee = 250,
        int $qty = 2,
    ): Order {
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 1000,
            'sold_count' => 0,
            'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'customer_name' => $name,
            'customer_email' => $email,
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => $subtotal,
            'booking_fee_minor' => $bookingFee,
            'application_fee_minor' => $applicationFee,
            'order_total_minor' => $subtotal + $bookingFee,
            'fulfilled_at' => now(),
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        OrderConsent::factory()->forOrder($order)->key('terms', true)->create();
        OrderConsent::factory()->forOrder($order)->key('privacy', true)->create();
        OrderConsent::factory()->forOrder($order)->key('marketing', false)->create();

        return $order;
    }

    // ---- Privacy policy page (22.3) -----------------------------------------

    public function test_privacy_policy_page_renders_publicly(): void
    {
        // Requirement 22.3 — the privacy policy page is public and renders.
        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertSee('Privacy Policy');
    }

    public function test_privacy_policy_page_is_not_treated_as_a_storefront_slug(): void
    {
        // /privacy is a reserved prefix, not a Company slug — even if a Company
        // named "privacy" existed, the reserved route wins and renders the
        // policy. (Requirement 22.3)
        Company::factory()->create(['slug' => 'privacy']);

        $this->get('/privacy')->assertOk()->assertSee('Privacy Policy');
    }

    // ---- Gating (3.3, 3.7, Property 6) ---------------------------------------

    public function test_owner_can_open_the_customers_page(): void
    {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/dashboard/customers')->assertOk();
    }

    public function test_non_owner_roles_cannot_run_gdpr_export_or_anonymise(): void
    {
        $company = Company::factory()->create();
        $token = CustomerController::tokenFor('someone@example.com');

        // GDPR export/anonymise is gated on ACTION_MANAGE_GDPR, held only by
        // the Owner and Admin. Box_Office, Accountant and Scanner are denied.
        foreach ([
            User::factory()->boxOffice()->create(['company_id' => $company->id]),
            User::factory()->accountant()->create(['company_id' => $company->id]),
            User::factory()->scanner()->create(['company_id' => $company->id]),
        ] as $user) {
            $this->actingAs($user)->post("/dashboard/customers/{$token}/export")->assertForbidden();
            $this->actingAs($user)->post("/dashboard/customers/{$token}/anonymise")->assertForbidden();
        }
    }

    public function test_admin_can_run_gdpr_export_and_anonymise(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $token = CustomerController::tokenFor('nobody@example.com');

        // Admins now handle GDPR data-subject requests alongside the Owner. The
        // customer has no orders, so both routes resolve without a 403 (export
        // returns an empty document; anonymise touches nothing and redirects).
        $this->actingAs($admin)->post("/dashboard/customers/{$token}/export")->assertOk();
        $this->actingAs($admin)->post("/dashboard/customers/{$token}/anonymise")->assertRedirect();
    }

    public function test_guests_are_redirected_from_the_customers_page(): void
    {
        $this->get('/dashboard/customers')->assertRedirect('/login');
    }

    // ---- Export (22.1, 22.4) -------------------------------------------------

    public function test_export_returns_the_customers_stored_data_including_consents(): void
    {
        $owner = User::factory()->owner()->create();
        $company = Company::find($owner->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $this->customerOrder($event, 'jo@example.com', name: 'Jo Bloggs', subtotal: 8_000, qty: 3);

        $token = CustomerController::tokenFor('jo@example.com');
        $response = $this->actingAs($owner)->post("/dashboard/customers/{$token}/export");

        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="gdpr-export.json"');

        $payload = $response->json();

        $this->assertSame('jo@example.com', $payload['customer_email']);
        $this->assertCount(1, $payload['orders']);

        $order = $payload['orders'][0];
        // Requirement 22.1 — every stored personal field is present.
        $this->assertSame('Jo Bloggs', $order['customer_name']);
        $this->assertSame('jo@example.com', $order['customer_email']);
        $this->assertSame(8_000, $order['ticket_subtotal_minor']);
        $this->assertCount(3, $order['tickets']);

        // Requirement 22.4 — captured consents are surfaced.
        $consentKeys = collect($order['consents'])->pluck('consent_key')->sort()->values()->all();
        $this->assertSame(['marketing', 'privacy', 'terms'], $consentKeys);
        $marketing = collect($order['consents'])->firstWhere('consent_key', 'marketing');
        $this->assertFalse($marketing['accepted']);
    }

    public function test_export_is_scoped_to_the_requesting_company(): void
    {
        $owner = User::factory()->owner()->create();
        $ownCompany = Company::find($owner->company_id);
        $ownEvent = Event::factory()->for($ownCompany)->unlimitedCapacity()->create();
        $this->customerOrder($ownEvent, 'shared@example.com', name: 'Own Customer');

        // A DIFFERENT Company holds an order for the SAME email — it must never
        // leak into this Company's export. (Requirement 22.5)
        $otherCompany = Company::factory()->create();
        $otherEvent = Event::factory()->for($otherCompany)->unlimitedCapacity()->create();
        $this->customerOrder($otherEvent, 'shared@example.com', name: 'Foreign Customer');

        $token = CustomerController::tokenFor('shared@example.com');
        $payload = $this->actingAs($owner)->post("/dashboard/customers/{$token}/export")->json();

        $this->assertCount(1, $payload['orders']);
        $this->assertSame('Own Customer', $payload['orders'][0]['customer_name']);
    }

    // ---- Anonymise (22.2) ----------------------------------------------------

    public function test_anonymise_scrubs_pii_but_retains_transactional_records(): void
    {
        $owner = User::factory()->owner()->create();
        $company = Company::find($owner->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $order = $this->customerOrder(
            $event,
            'erase-me@example.com',
            name: 'Erase Me',
            subtotal: 6_000,
            bookingFee: 400,
            applicationFee: 300,
            qty: 2,
        );
        $reference = $order->order_reference;

        $token = CustomerController::tokenFor('erase-me@example.com');
        $this->actingAs($owner)->post("/dashboard/customers/{$token}/anonymise")
            ->assertRedirect(route('dashboard.customers.index'));

        $order->refresh();

        // Personal data scrubbed. (Requirement 22.2)
        $this->assertSame(GdprService::ANONYMISED_NAME, $order->customer_name);
        $this->assertSame(GdprService::ANONYMISED_EMAIL, $order->customer_email);
        $this->assertNotSame('Erase Me', $order->customer_name);

        // Transactional records retained for reconciliation. (Requirement 22.2)
        $this->assertSame($reference, $order->order_reference);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(6_000, $order->ticket_subtotal_minor);
        $this->assertSame(400, $order->booking_fee_minor);
        $this->assertSame(300, $order->application_fee_minor);
        $this->assertSame(6_400, $order->order_total_minor);

        // The original email no longer resolves any personal data on export.
        $payload = $this->actingAs($owner)->post("/dashboard/customers/{$token}/export")->json();
        $this->assertCount(0, $payload['orders']);
    }

    public function test_anonymise_only_touches_the_requesting_companys_data(): void
    {
        $owner = User::factory()->owner()->create();
        $ownCompany = Company::find($owner->company_id);
        $ownEvent = Event::factory()->for($ownCompany)->unlimitedCapacity()->create();
        $ownOrder = $this->customerOrder($ownEvent, 'shared@example.com', name: 'Own Customer');

        // Another Company has an order for the same email. It must be untouched.
        // (Requirement 22.5, Property 1)
        $otherCompany = Company::factory()->create();
        $otherEvent = Event::factory()->for($otherCompany)->unlimitedCapacity()->create();
        $otherOrder = $this->customerOrder($otherEvent, 'shared@example.com', name: 'Foreign Customer');

        $token = CustomerController::tokenFor('shared@example.com');
        $this->actingAs($owner)->post("/dashboard/customers/{$token}/anonymise")
            ->assertRedirect();

        $ownOrder->refresh();
        $this->assertSame(GdprService::ANONYMISED_NAME, $ownOrder->customer_name);

        // The foreign Company's identical-email order is byte-for-byte
        // unchanged.
        $foreign = Order::withoutGlobalScopes()->find($otherOrder->id);
        $this->assertSame('Foreign Customer', $foreign->customer_name);
        $this->assertSame('shared@example.com', $foreign->customer_email);
    }

    // ---- Customer addressing -------------------------------------------------

    public function test_a_malformed_customer_token_is_not_found(): void
    {
        $owner = User::factory()->owner()->create();

        // An empty-decoding token yields a 404 rather than acting on nothing.
        $this->actingAs($owner)
            ->post('/dashboard/customers/'.CustomerController::tokenFor('').'/export')
            ->assertNotFound();
    }

    public function test_customer_detail_404s_for_an_unknown_customer(): void
    {
        $owner = User::factory()->owner()->create();

        $token = CustomerController::tokenFor('nobody@example.com');
        $this->actingAs($owner)->get("/dashboard/customers/{$token}")->assertNotFound();
    }
}
