<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ScanEvent;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app
 *
 * Task 9.2 — the live dashboard snapshot contract and its gating.
 *
 * Exercises {@see \App\Http\Controllers\Api\DashboardApiController::live()} end
 * to end through the real route stack (`auth:sanctum` + `api.tenant`), the way
 * the native client hits it. Each test issues a genuine device-scoped Sanctum
 * token (scoped to the acting Company, bound to a Device) and presents it as a
 * Bearer credential with the matching `X-Device-Id`, so `ResolveApiTenant`
 * binds the Active_Company exactly as in production and the Active_Event is
 * resolved tenant-scoped. This mirrors the token issuance in
 * {@see \Tests\PBT\ApiTenantIsolationTest}.
 *
 * What the four behaviours prove:
 *
 *   - Contract shape (7.2): a check-in-permitted operator gets 200 with the
 *     exact snapshot shape — event {id,name,starts_at}, stats
 *     {scanned,sold,remaining,rate}, a recent[] feed, and generatedAt.
 *   - No revenue (7.3): the payload carries NO sales/revenue framing anywhere —
 *     no revenue/gross/net/spend/payout/amount/price key appears at any depth.
 *   - Active_Event-only (7.4): with two eligible Events for the Company, the
 *     snapshot reflects ONLY the most imminent (the Active_Event) — its counts
 *     and its feed — never the company-wide totals across both Events.
 *   - Gating (7.5): a role without check_in (the Accountant) is denied 403 and
 *     no Event is resolved or read.
 *   - Idle state: a Company with no eligible Event gets 200 with a null event
 *     and zeroed stats rather than a 404, so the client can render idle.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class DashboardLiveSnapshotTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The Device the issued token is bound to. Every request presents this
     * value as `X-Device-Id` so `ResolveApiTenant`'s device-binding check
     * passes and the tenant is resolved.
     */
    private const DEVICE_ID = 'device-ios-scanner-dashboard';

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * A confirmed, (optionally) checked-in Order on the given Event, carrying
     * `$quantity` valid tickets of a freshly created Ticket_Type. The valid
     * tickets drive the attendance figures ("sold"); stamping `scanned_at`
     * makes the Order count as checked in ("scanned").
     */
    private function confirmedOrder(Event $event, int $quantity, bool $scanned = false): Order
    {
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'scanned_at' => $scanned ? now() : null,
        ]);

        $type = TicketType::factory()->forEvent($event)->create();

        Ticket::factory()
            ->count($quantity)
            ->forOrder($order)
            ->forTicketType($type)
            ->create();

        return $order->fresh();
    }

    /**
     * Record a scan_events breadcrumb row for the given Event under its
     * Company. Written scope-free so ownership is explicit; the dashboard reads
     * it back tenant-scoped via the Event's `event_id`.
     */
    private function recordScan(Event $event, string $outcome, string $reference): ScanEvent
    {
        return ScanEvent::withoutGlobalScopes()->create([
            'company_id' => $event->company_id,
            'event_id' => $event->id,
            'outcome' => $outcome,
            'reference' => $reference,
            'operator_name' => 'Door Operator',
            'tickets' => 1,
            'created_at' => now(),
        ]);
    }

    /**
     * Issue a real Sanctum personal access token for the user, scoped to the
     * given Company and bound to {@see DEVICE_ID}, mirroring what the
     * AuthController issuance persists: `current_company_id` carries the
     * Active_Company and `device_id` binds the token to its Device.
     *
     * @return array<string, string> Bearer + X-Device-Id headers
     */
    private function tokenHeaders(User $user, Company $company): array
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

    /**
     * A check-in-permitted operator who is a member of the given Company. The
     * Scanner role's one permitted action is check_in — exactly the Gate the
     * dashboard enforces.
     */
    private function scannerFor(Company $company): User
    {
        $user = User::factory()->for($company)->scanner()->create();
        $user->companies()->attach($company->id, ['role' => User::ROLE_SCANNER]);

        return $user;
    }

    /**
     * Recursively collect every string key appearing anywhere in a decoded
     * JSON structure, so a key-absence assertion can scan the whole tree rather
     * than only the top level.
     *
     * @param  mixed  $data
     * @return list<string>
     */
    private function allKeys($data): array
    {
        $keys = [];

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $keys[] = $key;
                }

                $keys = array_merge($keys, $this->allKeys($value));
            }
        }

        return $keys;
    }

    // ---- Contract shape (7.2) ----------------------------------------------

    public function test_live_snapshot_returns_the_active_event_contract_shape(): void
    {
        $company = Company::factory()->create();
        $scanner = $this->scannerFor($company);

        $event = Event::factory()->for($company)->create([
            'name' => 'Spring Gala',
            'is_published' => true,
            'starts_at' => now()->addHour(),
        ]);

        // Three tickets sold across two confirmed orders; the single-ticket
        // order is checked in (scanned = 1 person), the two-ticket order is not
        // (remaining = 2). Attendance counts PEOPLE (valid tickets), not orders.
        $this->confirmedOrder($event, 1, scanned: true);
        $this->confirmedOrder($event, 2, scanned: false);

        $this->recordScan($event, ScanEvent::OUTCOME_SUCCESS, 'REF-ABC');

        $response = $this->getJson('/api/dashboard/live', $this->tokenHeaders($scanner, $company));

        $response->assertOk()
            ->assertJsonStructure([
                'event' => ['id', 'name', 'starts_at'],
                'stats' => ['scanned', 'sold', 'remaining', 'rate'],
                'recent' => [
                    ['outcome', 'operator', 'reference', 'customer', 'tickets', 'at'],
                ],
                'generatedAt',
            ])
            ->assertJsonPath('event.id', $event->id)
            ->assertJsonPath('event.name', 'Spring Gala')
            // 3 sold, 1 scanned → 2 remaining, rate 33.3%.
            ->assertJsonPath('stats.scanned', 1)
            ->assertJsonPath('stats.sold', 3)
            ->assertJsonPath('stats.remaining', 2)
            ->assertJsonPath('stats.rate', 33.3);
    }

    // ---- No sales/revenue figures (7.3) -------------------------------------

    public function test_live_snapshot_omits_all_sales_and_revenue_figures(): void
    {
        $company = Company::factory()->create();
        $scanner = $this->scannerFor($company);

        $event = Event::factory()->for($company)->create([
            'is_published' => true,
            'starts_at' => now()->addHour(),
        ]);

        $this->confirmedOrder($event, 2, scanned: true);
        $this->recordScan($event, ScanEvent::OUTCOME_SUCCESS, 'REF-XYZ');

        $response = $this->getJson('/api/dashboard/live', $this->tokenHeaders($scanner, $company));
        $response->assertOk();

        // No money/revenue framing appears anywhere in the payload — at any
        // depth of the tree. (7.3)
        $keys = array_map('strtolower', $this->allKeys($response->json()));

        foreach (['revenue', 'gross', 'net', 'spend', 'payout', 'amount', 'price', 'money', 'fee'] as $forbidden) {
            foreach ($keys as $key) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $key,
                    "The live snapshot must carry no sales/revenue key; found '{$key}' matching '{$forbidden}'.",
                );
            }
        }
    }

    // ---- Active_Event-only (7.4) --------------------------------------------

    public function test_live_snapshot_reflects_only_the_most_imminent_active_event(): void
    {
        $company = Company::factory()->create();
        $scanner = $this->scannerFor($company);

        // The Active_Event — the most imminent published Event (starts soonest
        // from now).
        $active = Event::factory()->for($company)->create([
            'name' => 'Tonight at the Door',
            'is_published' => true,
            'starts_at' => now()->addMinutes(30),
        ]);

        // A second eligible Event further out. Its counts and feed must NOT
        // appear in the snapshot.
        $other = Event::factory()->for($company)->create([
            'name' => 'Next Month',
            'is_published' => true,
            'starts_at' => now()->addWeeks(4),
        ]);

        // Active_Event: 3 sold, 2 scanned.
        $this->confirmedOrder($active, 2, scanned: true);
        $this->confirmedOrder($active, 1, scanned: false);
        $this->recordScan($active, ScanEvent::OUTCOME_SUCCESS, 'ACTIVE-1');

        // Other Event: much larger numbers that would dominate a company-wide
        // tally — present only to prove they are excluded.
        $this->confirmedOrder($other, 10, scanned: true);
        $this->recordScan($other, ScanEvent::OUTCOME_SUCCESS, 'OTHER-1');
        $this->recordScan($other, ScanEvent::OUTCOME_DUPLICATE, 'OTHER-2');

        $response = $this->getJson('/api/dashboard/live', $this->tokenHeaders($scanner, $company));

        $response->assertOk()
            ->assertJsonPath('event.id', $active->id)
            ->assertJsonPath('event.name', 'Tonight at the Door')
            // Active_Event-only figures: 3 sold / 2 scanned — NOT the 13/12 a
            // company-wide tally across both Events would produce.
            ->assertJsonPath('stats.sold', 3)
            ->assertJsonPath('stats.scanned', 2)
            ->assertJsonPath('stats.remaining', 1);

        // The feed carries only the Active_Event's scan — never the other
        // Event's references.
        $references = collect($response->json('recent'))->pluck('reference')->all();

        $this->assertContains('ACTIVE-1', $references);
        $this->assertNotContains('OTHER-1', $references);
        $this->assertNotContains('OTHER-2', $references);
    }

    // ---- Role gating (7.5) --------------------------------------------------

    public function test_a_role_without_check_in_is_denied_403(): void
    {
        $company = Company::factory()->create();

        // The Accountant is the role without check_in. It is a genuine member
        // of the Company (so tenant binding succeeds) and is denied purely by
        // the Gate.
        $accountant = User::factory()->for($company)->accountant()->create();
        $accountant->companies()->attach($company->id, ['role' => User::ROLE_ACCOUNTANT]);

        Event::factory()->for($company)->create([
            'is_published' => true,
            'starts_at' => now()->addHour(),
        ]);

        $this->getJson('/api/dashboard/live', $this->tokenHeaders($accountant, $company))
            ->assertForbidden();
    }

    // ---- Idle state (no eligible Event) -------------------------------------

    public function test_live_snapshot_is_an_idle_null_event_when_no_eligible_event_exists(): void
    {
        $company = Company::factory()->create();
        $scanner = $this->scannerFor($company);

        // Only ineligible Events exist: an unpublished one and a cancelled one.
        Event::factory()->for($company)->create([
            'is_published' => false,
            'starts_at' => now()->addHour(),
        ]);
        Event::factory()->for($company)->create([
            'is_published' => true,
            'starts_at' => now()->addHour(),
            'cancelled_at' => now(),
        ]);

        $response = $this->getJson('/api/dashboard/live', $this->tokenHeaders($scanner, $company));

        $response->assertOk()
            ->assertJsonPath('event', null)
            ->assertJsonPath('stats.scanned', 0)
            ->assertJsonPath('stats.sold', 0)
            ->assertJsonPath('stats.remaining', 0)
            ->assertJsonPath('stats.rate', null)
            ->assertJsonPath('recent', []);
    }
}
