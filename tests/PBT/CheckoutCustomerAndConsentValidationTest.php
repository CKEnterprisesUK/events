<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderConsent;
use App\Models\Ticket;
use App\Models\TicketType;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Property-based test for checkout customer and consent validation
 * (design Property 12).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database and drives the real production path: a public Customer POSTs to
 * the checkout route so the property exercises the exact validation the
 * {@see \App\Http\Controllers\CheckoutController} applies in production.
 *
 * The rule under test (Requirements 10.1, 10.2, 10.3, 10.4, 22.4): a checkout
 * is accepted — creating an Order in `reserved` status with Tickets and stored
 * consents — if and only if
 *   - the customer name is 1–200 characters (non-blank), AND
 *   - the customer email is 1–254 characters and a valid email address, AND
 *   - every required consent (terms, privacy) was accepted,
 * while the remainder of the cart is valid (a real on-sale Ticket_Type of a
 * published Event, positive quantity within capacity). Otherwise the checkout
 * is rejected with a field/consent error and NO Order, Tickets, OrderConsents,
 * or reserved capacity are created. On an accepted checkout the stored consents
 * equal the submitted consents.
 *
 * The generators hit the field boundaries directly: name length incl.
 * 0/1/200/201; email incl. blank, invalid, valid, and over-254 length; and
 * consent combinations spanning missing-required vs all-required-present (with
 * an optional marketing consent mixed in).
 */
class CheckoutCustomerAndConsentValidationTest extends PbtTestCase
{
    use RefreshDatabase;

    private Company $company;

    private Event $event;

    private TicketType $ticketType;

    private string $slug;

    protected function setUp(): void
    {
        parent::setUp();

        // A single published Event with a charges-enabled Company and one
        // on-sale paid Ticket_Type reused across iterations. Any Order created
        // by an accepted iteration is removed with Eloquent deletes only (no
        // DDL / TRUNCATE, per the test-infra rule) and the held capacity is
        // reset, so each iteration starts from a clean, empty slate.
        $this->company = Company::factory()->create([
            'stripe_account_id' => 'acct_test123',
            'stripe_charges_enabled' => true,
            'fee_handling_mode' => Company::FEE_MODE_ABSORB,
            'company_fee_percent' => '10.00',
        ]);

        $this->event = Event::factory()
            ->for($this->company)
            ->published()
            ->unlimitedCapacity()
            ->create();

        $this->ticketType = TicketType::factory()
            ->forEvent($this->event)
            ->create([
                'price_minor' => 2_000,
                'capacity' => 1_000_000,
                'sold_count' => 0,
                'reserved_count' => 0,
                'sale_starts_at' => Carbon::now()->subDay(),
                'sale_ends_at' => Carbon::now()->addMonth(),
            ]);

        $this->slug = $this->company->slug;
    }

    /**
     * Candidate customer-name generator spanning the 1–200 boundary: the empty
     * string (invalid), a single char (min valid), exactly 200 (max valid), and
     * 201 (over-length, invalid), plus random lengths straddling the limit.
     */
    private function nameGenerator(): Generator
    {
        return Generator\oneOf(
            Generator\elements('', 'a', str_repeat('a', 200), str_repeat('a', 201)),
            Generator\map(
                fn (int $len): string => str_repeat('a', max(0, $len)),
                Generator\choose(0, 205),
            ),
        );
    }

    /**
     * Candidate email generator. Rather than reproduce Laravel's RFC email
     * parser in the oracle, values are drawn from two clearly-separated pools —
     * definitely valid and definitely invalid — so the oracle can decide
     * validity purely from which pool the value came from (plus the 1–254
     * length bound). Each value is emitted paired with its known validity.
     *
     * @return Generator<array{0: string, 1: bool}>
     */
    private function emailGenerator(): Generator
    {
        return Generator\oneOf(
            // Definitely-valid addresses (well under 254 chars).
            Generator\map(
                fn (string $email): array => [$email, true],
                Generator\elements(
                    'ada@example.test',
                    'grace.hopper@example.com',
                    'a@b.co',
                    'user+tag@sub.example.org',
                    'first.last@example.io',
                ),
            ),
            // Definitely-invalid strings (blank / malformed).
            Generator\map(
                fn (string $email): array => [$email, false],
                Generator\elements(
                    '',
                    'not-an-email',
                    'missing-at.example.com',
                    'no-domain@',
                    '@no-local.test',
                    'spaces in@email.test',
                    'two@@ats.test',
                ),
            ),
            // A valid local/domain shape but forced over the 254-char cap:
            // invalid on length alone.
            Generator\map(
                fn (int $pad): array => [str_repeat('a', 250 + $pad).'@example.test', false],
                Generator\choose(0, 20),
            ),
        );
    }

    /**
     * Candidate consent generator: an independent boolean per consent key. The
     * required keys (terms, privacy) span accepted/omitted; an optional
     * marketing flag is mixed in to confirm it never blocks a checkout.
     *
     * @return Generator<array{terms: bool, privacy: bool, marketing: bool}>
     */
    private function consentsGenerator(): Generator
    {
        return Generator\map(
            fn (array $flags): array => [
                'terms' => $flags[0],
                'privacy' => $flags[1],
                'marketing' => $flags[2],
            ],
            Generator\tuple(
                Generator\bool(),
                Generator\bool(),
                Generator\bool(),
            ),
        );
    }

    /**
     * The independently-computed oracle mirroring the controller's accept rule
     * for the customer + consent fields (the rest of the cart is always valid
     * in this property).
     *
     * @param  array{terms: bool, privacy: bool, marketing: bool}  $consents
     */
    private function expectedAccepted(string $name, string $email, bool $emailValid, array $consents): bool
    {
        $nameLen = strlen($name);
        $nameValid = $nameLen >= 1 && $nameLen <= 200;

        $emailLen = strlen($email);
        $emailAccepted = $emailValid && $emailLen >= 1 && $emailLen <= 254;

        $consentsValid = ($consents['terms'] ?? false) === true
            && ($consents['privacy'] ?? false) === true;

        return $nameValid && $emailAccepted && $consentsValid;
    }

    /**
     * Property 12: Checkout customer and consent validation — a checkout
     * creates an Order (reserved, with Tickets and stored consents) if and only
     * if the customer name is 1–200, the email is 1–254 and valid, and all
     * required consents were accepted; otherwise it is rejected with a
     * field/consent error and creates no Order, Tickets, consents, or reserved
     * capacity. Stored consents equal the submitted consents.
     *
     * **Validates: Requirements 10.1, 10.2, 10.3, 10.4, 22.4**
     */
    // Feature: event-ticketing-platform, Property 12: Checkout customer and consent validation — create Order iff name 1–200 + email 1–254 non-blank + all required consents accepted; else no Order + field/consent error; stored consents equal submitted
    public function test_checkout_accepted_iff_customer_and_consents_valid(): void
    {
        $route = "/{$this->slug}/{$this->event->id}/checkout";

        $this->forAll(
            $this->nameGenerator(),
            $this->emailGenerator(),
            $this->consentsGenerator(),
        )
            ->then(function (string $name, array $emailPair, array $consents) use ($route): void {
                [$email, $emailValid] = $emailPair;

                // Start every iteration from a clean, empty slate regardless of
                // what a prior iteration left behind. Reservations are held on
                // the ticket_types row via a raw increment, so reset the count
                // in the DB directly (Eloquent update only — no DDL / TRUNCATE,
                // per the test-infra rule) rather than through a possibly-stale
                // in-memory model.
                Order::withoutGlobalScopes()->forceDelete();
                Ticket::withoutGlobalScopes()->forceDelete();
                OrderConsent::withoutGlobalScopes()->forceDelete();
                TicketType::withoutGlobalScopes()
                    ->whereKey($this->ticketType->id)
                    ->update(['reserved_count' => 0]);

                $payload = [
                    'customer_name' => $name,
                    'customer_email' => $email,
                    'items' => [
                        ['ticket_type_id' => $this->ticketType->id, 'quantity' => 2],
                    ],
                    'consents' => $consents,
                ];

                $expectedAccepted = $this->expectedAccepted($name, $email, $emailValid, $consents);

                $response = $this->post($route, $payload);

                $context = sprintf(
                    'name(len=%d) email(len=%d, valid=%s)=%s consents=%s -> expected %s',
                    strlen($name),
                    strlen($email),
                    $emailValid ? 'true' : 'false',
                    var_export($email, true),
                    json_encode($consents),
                    $expectedAccepted ? 'accept' : 'reject',
                );

                $order = Order::withoutGlobalScopes()->first();

                if ($expectedAccepted) {
                    // Accepted: a redirect and exactly one reserved Order with
                    // its Tickets, consents, and held capacity.
                    $response->assertRedirect();
                    $response->assertSessionHasNoErrors();

                    $this->assertNotNull($order, "Accepted checkout created no Order: {$context}");
                    $this->assertSame(Order::STATUS_RESERVED, $order->status, "Order not reserved: {$context}");
                    $this->assertSame($name, $order->customer_name, "name mismatch: {$context}");
                    $this->assertSame($email, $order->customer_email, "email mismatch: {$context}");

                    // One Ticket per purchased ticket (quantity 2).
                    $this->assertSame(
                        2,
                        Ticket::withoutGlobalScopes()->where('order_id', $order->id)->count(),
                        "ticket count mismatch: {$context}",
                    );

                    // Stored consents equal the submitted consents. (22.4)
                    $stored = OrderConsent::withoutGlobalScopes()
                        ->where('order_id', $order->id)
                        ->pluck('accepted', 'consent_key');

                    foreach ($consents as $key => $submitted) {
                        $this->assertTrue(
                            $stored->has($key),
                            "consent '{$key}' not stored: {$context}",
                        );
                        $this->assertSame(
                            $submitted,
                            (bool) $stored[$key],
                            "consent '{$key}' value mismatch: {$context}",
                        );
                    }

                    // Capacity held for the two reserved tickets.
                    $this->assertSame(
                        2,
                        $this->ticketType->fresh()->reserved_count,
                        "reserved capacity mismatch: {$context}",
                    );

                    // Cleanup for the next iteration happens at the top of the
                    // closure (Order/Ticket/OrderConsent deletes + reserved_count
                    // reset), so nothing further is required here.
                } else {
                    // Rejected: a validation error and nothing persisted.
                    $response->assertSessionHasErrors();

                    $this->assertNull($order, "Rejected checkout created an Order: {$context}");
                    $this->assertSame(
                        0,
                        Ticket::withoutGlobalScopes()->count(),
                        "Rejected checkout created Tickets: {$context}",
                    );
                    $this->assertSame(
                        0,
                        OrderConsent::withoutGlobalScopes()->count(),
                        "Rejected checkout created OrderConsents: {$context}",
                    );
                    $this->assertSame(
                        0,
                        $this->ticketType->fresh()->reserved_count,
                        "Rejected checkout reserved capacity: {$context}",
                    );
                }
            });
    }
}
