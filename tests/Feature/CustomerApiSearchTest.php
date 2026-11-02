<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app — task 10.2
 *
 * Feature coverage for the tenant-scoped Customer search endpoint
 * ({@see \App\Http\Controllers\Api\CustomerApiController::index()}), the API
 * re-expression of the web Customers roster for the native scanner app.
 *
 * The endpoint runs under `GET /api/customers` behind `auth:sanctum` and the
 * `api.tenant` group, and gates on `ACTION_MANAGE_ORDERS` before reading any
 * data. It returns one aggregate row per distinct `customer_email` — `{name,
 * email, orders_count, confirmed_spend_minor}` — over an optional free-text
 * name/email search, paginated with `meta`.
 *
 * These tests lock down the four behaviours the task calls out:
 *
 *   1. Matching rows carry the right aggregate — `orders_count` counts every
 *      Order under that email, and `confirmed_spend_minor` sums ONLY the PAID
 *      and FREE_CONFIRMED Orders (an unconfirmed/reserved Order never adds to
 *      spend). (Requirements 8.1, 8.2)
 *   2. Role gating — a role WITHOUT `orders` (Scanner) is denied 403 and reads
 *      no data. (Requirement 8.3)
 *   3. Tenant isolation — a request scoped to Company A never returns Company
 *      B's Customer rows. (Requirements 8.2, 12.3)
 *   4. Search — the `q` term filters by both customer name and customer email.
 *      (Requirement 8.1)
 *
 * The token is issued the real way (a device-scoped Sanctum personal access
 * token presented as a Bearer header with the matching `X-Device-Id`),
 * mirroring {@see \App\Http\Controllers\Api\AuthController::issueToken()} and
 * the sibling API tests ({@see \Tests\PBT\ApiTenantIsolationTest}), because
 * `ResolveApiTenant` reads the resolving token's `current_company_id` and
 * `device_id` off `currentAccessToken()` — a transient `Sanctum::actingAs`
 * token carries neither.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class CustomerApiSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The device the A-scoped token is bound to. Every request presents this
     * value as `X-Device-Id` so `ResolveApiTenant`'s device-binding check
     * passes and the Active_Company resolves.
     */
    private const DEVICE_ID = 'device-ios-scanner-customers';

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * A matching row carries the Customer's order count and confirmed spend,
     * where confirmed spend sums ONLY the PAID and FREE_CONFIRMED Orders — a
     * reserved/unconfirmed Order is counted in `orders_count` but never adds to
     * `confirmed_spend_minor`. (Requirements 8.1, 8.2)
     */
    public function test_matching_rows_carry_order_count_and_confirmed_spend(): void
    {
        $companyA = Company::factory()->create();

        $operator = $this->boxOfficeMemberOf($companyA);

        // A single Customer (one distinct email) with three Orders under
        // Company A: one PAID, one FREE_CONFIRMED, and one still reserved
        // (unconfirmed). Confirmed spend must be PAID + FREE_CONFIRMED only; the
        // reserved Order adds to the count but never to the spend.
        $email = 'ada@example.com';
        $name = 'Ada Lovelace';

        $paid = Order::factory()->for($companyA)->paid()->create([
            'customer_name' => $name,
            'customer_email' => $email,
            'order_total_minor' => 5_000,
        ]);

        // A free-confirmed Order contributes its (zero) total to confirmed
        // spend, so to make the sum unambiguous we add a confirmed PAID Order
        // with a known total and a reserved Order that must NOT count.
        Order::factory()->for($companyA)->freeConfirmed()->create([
            'customer_name' => $name,
            'customer_email' => $email,
        ]);

        $reservedTotal = 9_999;
        Order::factory()->for($companyA)->create([
            'customer_name' => $name,
            'customer_email' => $email,
            'status' => Order::STATUS_RESERVED,
            'order_total_minor' => $reservedTotal,
        ]);

        $headers = $this->tokenHeadersFor($operator, $companyA);

        $response = $this->getJson('/api/customers?q=Ada', $headers)->assertOk();

        // Exactly one aggregate row (one distinct email), carrying the full
        // order count and the confirmed-only spend.
        $response->assertJsonCount(1, 'customers');
        $response->assertJsonPath('customers.0.email', $email);
        $response->assertJsonPath('customers.0.name', $name);
        // Three Orders total under this email (paid + free_confirmed + reserved).
        $response->assertJsonPath('customers.0.orders_count', 3);
        // Confirmed spend = PAID (5_000) + FREE_CONFIRMED (0). The reserved
        // Order's 9_999 is deliberately excluded.
        $response->assertJsonPath('customers.0.confirmed_spend_minor', $paid->order_total_minor);
    }

    /**
     * An unconfirmed (reserved) Order never adds to confirmed spend, even when
     * it is the Customer's only Order — the row still appears (it has Orders)
     * but its confirmed spend is zero. (Requirement 8.2)
     */
    public function test_unconfirmed_order_does_not_add_to_confirmed_spend(): void
    {
        $companyA = Company::factory()->create();
        $operator = $this->boxOfficeMemberOf($companyA);

        Order::factory()->for($companyA)->create([
            'customer_name' => 'Grace Hopper',
            'customer_email' => 'grace@example.com',
            'status' => Order::STATUS_RESERVED,
            'order_total_minor' => 12_345,
        ]);

        $headers = $this->tokenHeadersFor($operator, $companyA);

        $this->getJson('/api/customers?q=grace', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.orders_count', 1)
            ->assertJsonPath('customers.0.confirmed_spend_minor', 0);
    }

    /**
     * Role gating: a role WITHOUT the `orders` action (Scanner) is denied 403
     * and reads no Customer data. (Requirement 8.3)
     */
    public function test_role_without_orders_action_is_denied_403(): void
    {
        $companyA = Company::factory()->create();

        // Seed a Customer so a leak (rather than a clean denial) would be
        // visible if the gate failed open.
        Order::factory()->for($companyA)->paid()->create([
            'customer_name' => 'Alan Turing',
            'customer_email' => 'alan@example.com',
        ]);

        // A Scanner is a member of Company A but holds only ACTION_CHECK_IN —
        // not ACTION_MANAGE_ORDERS.
        $scanner = User::factory()->for($companyA)->scanner()->create();
        $scanner->companies()->attach($companyA->id, ['role' => User::ROLE_SCANNER]);

        $headers = $this->tokenHeadersFor($scanner, $companyA);

        $this->getJson('/api/customers', $headers)->assertForbidden();
    }

    /**
     * Tenant isolation: a request scoped to Company A never returns Company B's
     * Customer rows. The A-scoped token resolves tenant A, so the global
     * `company_id` scope confines the aggregate to A — B's Customers never leak.
     * (Requirements 8.2, 12.3)
     */
    public function test_request_scoped_to_company_a_never_returns_company_b_customers(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();

        $operator = $this->boxOfficeMemberOf($companyA);

        // A Customer under Company A (should appear) and a Customer under
        // Company B (must never appear).
        Order::factory()->for($companyA)->paid()->create([
            'customer_name' => 'Company A Customer',
            'customer_email' => 'a-customer@example.com',
        ]);

        $bEmail = 'b-customer@example.com';
        Order::factory()->for($companyB)->paid()->create([
            'customer_name' => 'Company B Customer',
            'customer_email' => $bEmail,
        ]);

        $headers = $this->tokenHeadersFor($operator, $companyA);

        $response = $this->getJson('/api/customers', $headers)->assertOk();

        // Only Company A's single Customer is returned.
        $response->assertJsonCount(1, 'customers');
        $response->assertJsonPath('customers.0.email', 'a-customer@example.com');

        // Company B's Customer appears nowhere in the payload.
        $response->assertJsonMissing(['email' => $bEmail]);
        $this->assertStringNotContainsString($bEmail, $response->getContent());
    }

    /**
     * Search filters by Customer name. A `q` term matching one Customer's name
     * returns that Customer and excludes the non-matching one. (Requirement 8.1)
     */
    public function test_search_filters_by_name(): void
    {
        $companyA = Company::factory()->create();
        $operator = $this->boxOfficeMemberOf($companyA);

        Order::factory()->for($companyA)->paid()->create([
            'customer_name' => 'Katherine Johnson',
            'customer_email' => 'katherine@example.com',
        ]);
        Order::factory()->for($companyA)->paid()->create([
            'customer_name' => 'Dorothy Vaughan',
            'customer_email' => 'dorothy@example.com',
        ]);

        $headers = $this->tokenHeadersFor($operator, $companyA);

        $this->getJson('/api/customers?q=Katherine', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.email', 'katherine@example.com')
            ->assertJsonMissing(['email' => 'dorothy@example.com']);
    }

    /**
     * Search filters by Customer email. A `q` term matching one Customer's
     * email returns that Customer and excludes the non-matching one.
     * (Requirement 8.1)
     */
    public function test_search_filters_by_email(): void
    {
        $companyA = Company::factory()->create();
        $operator = $this->boxOfficeMemberOf($companyA);

        Order::factory()->for($companyA)->paid()->create([
            'customer_name' => 'Margaret Hamilton',
            'customer_email' => 'margaret@apollo.test',
        ]);
        Order::factory()->for($companyA)->paid()->create([
            'customer_name' => 'Annie Easley',
            'customer_email' => 'annie@nasa.test',
        ]);

        $headers = $this->tokenHeadersFor($operator, $companyA);

        $this->getJson('/api/customers?q=apollo.test', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.email', 'margaret@apollo.test')
            ->assertJsonMissing(['email' => 'annie@nasa.test']);
    }

    /**
     * A Box_Office user who is a member of the given Company. Box_Office holds
     * ACTION_MANAGE_ORDERS (so it passes the endpoint's gate) without the
     * broader reporting/settings authority, making it a clean "permitted"
     * fixture for this surface.
     */
    private function boxOfficeMemberOf(Company $company): User
    {
        $user = User::factory()->for($company)->boxOffice()->create();
        $user->companies()->attach($company->id, ['role' => User::ROLE_BOX_OFFICE]);

        return $user;
    }

    /**
     * Bearer + device headers for a real, device-scoped Sanctum token bound to
     * the given Company, mirroring what the production AuthController issuance
     * persists so `ResolveApiTenant` resolves the Active_Company off the token.
     *
     * @return array<string, string>
     */
    private function tokenHeadersFor(User $user, Company $company): array
    {
        $newToken = $user->createToken(self::DEVICE_ID);

        $newToken->accessToken->forceFill([
            'current_company_id' => $company->id,
            'device_id' => self::DEVICE_ID,
        ])->save();

        return [
            'Authorization' => 'Bearer '.$newToken->plainTextToken,
            'X-Device-Id' => self::DEVICE_ID,
        ];
    }
}
