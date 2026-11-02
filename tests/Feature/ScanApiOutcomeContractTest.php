<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\QrService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app — task 8.2
 *
 * Maps every Scan_Outcome to the JSON contract the native client reads back
 * from `POST /api/scan`. This is the API twin of {@see ScanCheckInTest} (which
 * asserts the web scanner's rendered message text) and
 * {@see ScanControllerRegressionTest} (which asserts the web live-scanner JSON
 * fragment); here the assertions are against the raw Scan_Outcome JSON body the
 * {@see \App\Http\Controllers\Api\ScanApiController} serialises.
 *
 * Every resolved outcome is a 200 — the outcome is the payload, not an HTTP
 * error, so a door operator always gets a structured result (only a denied role
 * or a bad/expired token is a non-200, covered elsewhere). The contract shape,
 * per {@see \App\Http\Controllers\Api\ScanApiController::serialise()}:
 *
 *   - checked_in      status, message, reference, customer, scanned_at, breakdown[]
 *   - already_scanned status, message, reference, customer, scanned_at, scanned_by
 *   - rejected/failed/invalid/unreadable  status + message ONLY (no richer keys)
 *
 * Each test asserts both the keys that MUST be present for that outcome AND
 * that the richer keys are ABSENT for the status-only outcomes, so the body
 * matches the contract exactly with no null padding.
 *
 * The endpoint is hit the real way: a check-in-permitted Scanner whose Sanctum
 * token is scoped to a Company (`current_company_id`) and bound to a device,
 * presented as a Bearer header alongside the matching `X-Device-Id` — exactly
 * what {@see \App\Http\Middleware\ResolveApiTenant} reads to bind the tenant.
 * A real token (not `Sanctum::actingAs`) is required because the middleware
 * reads `current_company_id`/`device_id` off `currentAccessToken()`, which a
 * transient acting-as token does not carry.
 *
 * Requirements: 5.3 (unreadable), 5.4 (invalid), 5.5 (rejected), 5.6 (failed),
 * 5.7 (already_scanned), 5.8 (checked_in), 6.2 (blank input → unreadable).
 *
 * NOTE: Authored but NOT executed — MySQL is unreachable in this environment so
 * the PHPUnit suite cannot run here. Verified with `php -l` and by review.
 */
class ScanApiOutcomeContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The device the Scanner's token is bound to. Every request presents this
     * as `X-Device-Id` so `ResolveApiTenant`'s device-binding check passes.
     */
    private const DEVICE_ID = 'device-ios-scanner';

    private QrService $qr;

    private Company $company;

    private User $scanner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrService::class);

        // A Company and a check-in-permitted Scanner who is a MEMBER of it via
        // the company_user pivot — membership (not the single home company) is
        // what ResolveApiTenant verifies before binding the token's tenant.
        $this->company = Company::factory()->create();
        $this->scanner = User::factory()->for($this->company)->scanner()->create();
        $this->scanner->companies()->attach($this->company->id, ['role' => User::ROLE_SCANNER]);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    // ---- Fixtures -----------------------------------------------------------

    /**
     * A confirmed, unscanned Order belonging to the acting Scanner's Company,
     * with `$quantities` tickets per Ticket_Type name. Mirrors the helper in
     * {@see ScanCheckInTest}/{@see ScanControllerRegressionTest} so all three
     * suites build fixtures identically.
     *
     * @param  array<string, int>  $quantities
     */
    private function confirmedOrder(array $quantities = ['General' => 2]): Order
    {
        $event = Event::factory()->for($this->company)->create();

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'scanned_at' => null,
            'scanned_by' => null,
        ]);

        foreach ($quantities as $name => $count) {
            $type = TicketType::factory()->forEvent($event)->create(['name' => $name]);

            Ticket::factory()
                ->count($count)
                ->forOrder($order)
                ->forTicketType($type)
                ->create();
        }

        return $order->fresh();
    }

    /**
     * Issue a real Sanctum token for the acting Scanner, scoped to the acting
     * Company and bound to {@see DEVICE_ID}, and return the headers to present
     * it with — mirroring what the AuthController issuance persists
     * (`current_company_id` + `device_id`) and what every authenticated API
     * request carries.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $newToken = $this->scanner->createToken(self::DEVICE_ID);

        $newToken->accessToken->forceFill([
            'current_company_id' => $this->company->id,
            'device_id' => self::DEVICE_ID,
        ])->save();

        return [
            'Authorization' => 'Bearer '.$newToken->plainTextToken,
            'X-Device-Id' => self::DEVICE_ID,
        ];
    }

    /**
     * POST to the real scan endpoint as the authenticated, device-bound
     * Scanner with the given body.
     *
     * @param  array<string, mixed>  $body
     */
    private function scan(array $body): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/scan', $body, $this->authHeaders());
    }

    // ---- checked_in (5.8) ---------------------------------------------------

    public function test_checked_in_returns_the_full_contract_with_breakdown(): void
    {
        $order = $this->confirmedOrder(['VIP' => 1, 'General' => 3]);
        $payload = $this->qr->payload($order->order_reference);

        $response = $this->scan(['payload' => $payload]);

        $response->assertStatus(200);

        // 5.8 — the checked_in contract: status, message, reference, customer,
        // scanned_at (ISO-8601) and the Ticket_Type + quantity breakdown.
        $response->assertJsonPath('status', 'checked_in');
        $response->assertJsonPath('message', 'Checked in.');
        $response->assertJsonPath('reference', $order->order_reference);
        $response->assertJsonPath('customer', $order->customer_name);

        $data = $response->json();
        $this->assertArrayHasKey('scanned_at', $data);
        $this->assertNotNull($data['scanned_at']);
        // ISO-8601 instant (what toIso8601String() produces).
        $this->assertNotFalse(strtotime($data['scanned_at']));

        // breakdown is a list of { name, quantity } rows, ordered by name.
        $this->assertSame(
            [
                ['name' => 'General', 'quantity' => 3],
                ['name' => 'VIP', 'quantity' => 1],
            ],
            $data['breakdown'],
        );

        // The already_scanned-only key is absent on a fresh check-in.
        $response->assertJsonMissingPath('scanned_by');

        // The Order was checked in exactly once by the acting Scanner.
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->scanned_at);
        $this->assertSame($this->scanner->id, $fresh->scanned_by);
    }

    // ---- already_scanned (5.7) ----------------------------------------------

    public function test_already_scanned_returns_scanned_at_and_operator(): void
    {
        $order = $this->confirmedOrder();
        $payload = $this->qr->payload($order->order_reference);

        // First scan checks in.
        $this->scan(['payload' => $payload])->assertJsonPath('status', 'checked_in');

        $firstScannedAt = $order->fresh()->scanned_at;

        // 5.7 — a second scan reports already_scanned carrying the ORIGINAL
        // scanned_at and the name of the operator who checked it in.
        $response = $this->scan(['payload' => $payload]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'already_scanned');
        $response->assertJsonPath('message', 'Already scanned.');
        $response->assertJsonPath('reference', $order->order_reference);
        $response->assertJsonPath('customer', $order->customer_name);
        $response->assertJsonPath('scanned_by', $this->scanner->name);

        $data = $response->json();
        $this->assertArrayHasKey('scanned_at', $data);
        $this->assertNotNull($data['scanned_at']);
        $this->assertSame(
            $firstScannedAt->toIso8601String(),
            $data['scanned_at'],
        );

        // The checked_in-only key is absent on an already-scanned result.
        $response->assertJsonMissingPath('breakdown');

        // The recorded scanned_at never moves on a re-scan.
        $this->assertSame(
            $firstScannedAt->toDateTimeString(),
            $order->fresh()->scanned_at->toDateTimeString(),
        );
    }

    // ---- rejected (5.5) -----------------------------------------------------

    public function test_rejected_returns_status_and_message_only(): void
    {
        // 5.5 — a well-signed payload for a reference that matches no Order
        // under the tenant scope (unknown or another Company's).
        $payload = $this->qr->payload('NOSUCHREF0001');

        $response = $this->scan(['payload' => $payload]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('message', 'Ticket not found.');

        // Status + message ONLY — none of the richer keys are present.
        $this->assertSame(
            ['status', 'message'],
            array_keys($response->json()),
        );
    }

    // ---- failed (5.6) -------------------------------------------------------

    public function test_failed_returns_status_and_message_only_for_a_voided_order(): void
    {
        $event = Event::factory()->for($this->company)->create();
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_VOIDED,
        ]);

        $payload = $this->qr->payload($order->order_reference);

        // 5.6 — a voided (non-confirmed) Order fails at scan.
        $response = $this->scan(['payload' => $payload]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'failed');
        $response->assertJsonPath('message', 'Failed. This ticket is no longer valid.');

        // Status + message ONLY.
        $this->assertSame(
            ['status', 'message'],
            array_keys($response->json()),
        );

        $this->assertNull($order->fresh()->scanned_at);
    }

    // ---- invalid (5.4) ------------------------------------------------------

    public function test_invalid_returns_status_and_message_only_for_a_tampered_token(): void
    {
        $order = $this->confirmedOrder();

        // 5.4 — a well-shaped payload whose token is not the real HMAC; the
        // recomputed HMAC over the reference detects the tamper.
        $tampered = $order->order_reference.'.'.str_repeat('0', 64);

        $response = $this->scan(['payload' => $tampered]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'invalid');
        $response->assertJsonPath('message', 'Invalid code. This ticket could not be verified.');

        // Status + message ONLY.
        $this->assertSame(
            ['status', 'message'],
            array_keys($response->json()),
        );

        // A tampered code never checks the Order in.
        $this->assertNull($order->fresh()->scanned_at);
    }

    // ---- unreadable (5.3) ---------------------------------------------------

    public function test_unreadable_returns_status_and_message_only_for_an_undecodable_payload(): void
    {
        // 5.3 — a payload carrying the `.` separator but an empty token side
        // decodes to nothing and is unreadable.
        $response = $this->scan(['payload' => 'ABC123.']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'unreadable');
        $response->assertJsonPath('message', 'Unreadable code. Please try scanning again.');

        // Status + message ONLY.
        $this->assertSame(
            ['status', 'message'],
            array_keys($response->json()),
        );
    }

    public function test_blank_input_is_unreadable(): void
    {
        // 6.2 — neither a payload nor a reference supplied resolves to the
        // empty string, which is unreadable (nothing scanned/typed at all).
        $response = $this->scan([]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'unreadable');
        $response->assertJsonPath('message', 'Unreadable code. Please try scanning again.');

        $this->assertSame(
            ['status', 'message'],
            array_keys($response->json()),
        );
    }
}
