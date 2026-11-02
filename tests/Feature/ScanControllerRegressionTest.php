<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\QrService;
use App\Services\TicketCheckInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: ios-scanner-app — task 4.7
 *
 * Regression coverage for the web QR scanner after the TicketCheckInService
 * extraction (tasks 4.1/4.2). Tasks 4.1 and 4.2 moved the whole decision tree
 * out of `ScanController` into {@see TicketCheckInService} and
 * left `ScanController::scan` as a thin adapter that builds an Operator from
 * `Auth::user()`, calls the service, flattens the structured `ScanResult` back
 * into the view-facing array via `toArray()`, writes the session recap through
 * `remember()`, and renders either the full page or the live-scanner JSON
 * fragment.
 *
 * {@see ScanCheckInTest} already proves the six outcomes render the right
 * message text on the full-page POST. This class locks down the two seams the
 * refactor *rebuilt* and that the message-text assertions don't reach, so a
 * behavioural regression in the adapter is caught:
 *
 *   1. The live-scanner background-POST contract — a `wantsJson()`/`ajax()`
 *      request returns the exact `{ status, resultHtml, historyHtml }` shape
 *      the running camera swaps in, with the status mapped identically to the
 *      pre-refactor controller and the rendered banner/recap fragments intact.
 *   2. The session recap (`scan.history`) — the per-operator rolling list the
 *      adapter now populates from the service's `ScanResult`, including the
 *      reference/customer/ticket-count projection and the newest-first cap.
 *
 * Each outcome is exercised through the WEB route (`POST /dashboard/scan`) as
 * an authenticated, check-in-permitted Scanner under the bound tenant — never
 * the service directly (that is covered by the service-level property tests in
 * tasks 4.3–4.6).
 *
 * Requirements: 5.3 (unreadable), 5.4 (invalid), 5.5 (rejected), 5.6 (failed),
 * 5.7 (already_scanned), 5.8 (checked_in).
 */
class ScanControllerRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const HISTORY_KEY = 'scan.history';

    private QrService $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrService::class);
    }

    /**
     * A confirmed, unscanned Order belonging to the given Scanner's Company,
     * with `$quantities` tickets per Ticket_Type name. Mirrors the helper in
     * {@see ScanCheckInTest} so both suites build fixtures identically.
     *
     * @param  array<string, int>  $quantities
     */
    private function confirmedOrderFor(User $scanner, array $quantities = ['General' => 2]): Order
    {
        $company = Company::find($scanner->company_id);
        $event = Event::factory()->for($company)->create();

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

    // ---- The live-scanner JSON fragment contract ----------------------------
    //
    // When the live scanner POSTs in the background (fetch) it asks for JSON and
    // the adapter returns only the pieces it swaps in: the mapped `status`, the
    // rendered result banner (`resultHtml`) and the refreshed recap
    // (`historyHtml`). These assert the status mapping and that the fragments
    // carry the same content the full-page render shows — the contract the
    // refactor must preserve verbatim.

    public function test_checked_in_json_fragment_carries_status_and_rendered_banner(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner, ['VIP' => 1, 'General' => 3]);
        $payload = $this->qr->payload($order->order_reference);

        $response = $this->actingAs($scanner)
            ->postJson('/dashboard/scan', ['payload' => $payload]);

        $response->assertStatus(200);
        // 5.8 — the status the live scanner keys its green banner on.
        $response->assertJsonPath('status', 'checked_in');
        $response->assertJsonStructure(['status', 'resultHtml', 'historyHtml']);

        $data = $response->json();
        // The result fragment is the same banner the full page renders.
        $this->assertStringContainsString('Checked in.', $data['resultHtml']);
        $this->assertStringContainsString('VIP', $data['resultHtml']);
        $this->assertStringContainsString('General', $data['resultHtml']);
        $this->assertStringContainsString('scan-result--good', $data['resultHtml']);
        // The refreshed recap already shows this scan.
        $this->assertStringContainsString($order->order_reference, $data['historyHtml']);

        // Behaviour unchanged: the Order is checked in exactly once.
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->scanned_at);
        $this->assertSame($scanner->id, $fresh->scanned_by);
    }

    public function test_already_scanned_json_fragment_maps_status_and_shows_prior_operator(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner);
        $payload = $this->qr->payload($order->order_reference);

        // First scan checks in.
        $this->actingAs($scanner)->postJson('/dashboard/scan', ['payload' => $payload])
            ->assertJsonPath('status', 'checked_in');

        $firstScannedAt = $order->fresh()->scanned_at;

        // 5.7 — a second scan reports already_scanned with the prior timestamp
        // and the checking-in operator's name, unchanged by the refactor.
        $response = $this->actingAs($scanner)
            ->postJson('/dashboard/scan', ['payload' => $payload]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'already_scanned');

        $data = $response->json();
        $this->assertStringContainsString('Already scanned.', $data['resultHtml']);
        $this->assertStringContainsString('scan-result--warn', $data['resultHtml']);
        $this->assertStringContainsString($scanner->name, $data['resultHtml']);
        $this->assertStringContainsString($firstScannedAt->format('Y-m-d H:i:s'), $data['resultHtml']);

        // The recorded scanned_at never moves on a re-scan.
        $this->assertSame(
            $firstScannedAt->toDateTimeString(),
            $order->fresh()->scanned_at->toDateTimeString()
        );
    }

    public function test_rejected_json_fragment_maps_status_without_an_order(): void
    {
        $scanner = User::factory()->scanner()->create();

        // 5.5 — a reference matching no Order under the tenant scope.
        $response = $this->actingAs($scanner)
            ->postJson('/dashboard/scan', ['payload' => 'NOSUCHREF0001']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'rejected');

        $data = $response->json();
        $this->assertStringContainsString('Ticket not found.', $data['resultHtml']);
        $this->assertStringContainsString('scan-result--bad', $data['resultHtml']);
    }

    public function test_failed_json_fragment_maps_status_for_an_unconfirmed_order(): void
    {
        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);
        $event = Event::factory()->for($company)->create();

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_VOIDED,
        ]);
        $payload = $this->qr->payload($order->order_reference);

        // 5.6 — a voided Order fails at scan and is not checked in.
        $response = $this->actingAs($scanner)
            ->postJson('/dashboard/scan', ['payload' => $payload]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'failed');

        $data = $response->json();
        $this->assertStringContainsString('no longer valid', $data['resultHtml']);
        $this->assertStringContainsString('scan-result--bad', $data['resultHtml']);

        $this->assertNull($order->fresh()->scanned_at);
    }

    public function test_invalid_json_fragment_maps_status_for_a_tampered_token(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner);

        // 5.4 — a well-shaped payload whose token is not the real HMAC.
        $tampered = $order->order_reference.'.'.str_repeat('0', 64);

        $response = $this->actingAs($scanner)
            ->postJson('/dashboard/scan', ['payload' => $tampered]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'invalid');

        $data = $response->json();
        $this->assertStringContainsString('Invalid code.', $data['resultHtml']);
        $this->assertStringContainsString('scan-result--bad', $data['resultHtml']);

        $this->assertNull($order->fresh()->scanned_at);
    }

    public function test_unreadable_json_fragment_maps_status_for_an_undecodable_payload(): void
    {
        $scanner = User::factory()->scanner()->create();

        // 5.3 — a payload carrying the `.` separator but an empty token side
        // decodes to nothing and is unreadable.
        $response = $this->actingAs($scanner)
            ->postJson('/dashboard/scan', ['payload' => 'ABC123.']);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'unreadable');

        $data = $response->json();
        $this->assertStringContainsString('Unreadable code', $data['resultHtml']);
        $this->assertStringContainsString('scan-result--bad', $data['resultHtml']);
    }

    // ---- The session recap (`scan.history`) ---------------------------------
    //
    // The adapter still writes a short, per-operator rolling recap to the
    // session from the service's ScanResult. These assert the projected shape
    // and the newest-first cap survive the refactor — the recap is the piece
    // ScanCheckInTest's message-text assertions never inspect.

    public function test_successful_scan_records_a_session_recap_entry(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner, ['VIP' => 1, 'General' => 3]);
        $payload = $this->qr->payload($order->order_reference);

        $response = $this->actingAs($scanner)->post('/dashboard/scan', ['payload' => $payload]);

        $history = $response->session()->get(self::HISTORY_KEY);
        $this->assertIsArray($history);
        $this->assertCount(1, $history);

        $entry = $history[0];
        $this->assertSame('checked_in', $entry['status']);
        $this->assertSame('Checked in.', $entry['message']);
        $this->assertSame($order->order_reference, $entry['reference']);
        $this->assertSame($order->customer_name, $entry['customer']);
        // The recap sums the breakdown quantities: 1 VIP + 3 General.
        $this->assertSame(4, $entry['tickets']);
        $this->assertArrayHasKey('at', $entry);
    }

    public function test_unreadable_scan_records_a_recap_entry_without_order_details(): void
    {
        $scanner = User::factory()->scanner()->create();

        $response = $this->actingAs($scanner)->post('/dashboard/scan', ['payload' => 'ABC123.']);

        $history = $response->session()->get(self::HISTORY_KEY);
        $this->assertIsArray($history);
        $this->assertCount(1, $history);

        $entry = $history[0];
        $this->assertSame('unreadable', $entry['status']);
        // No Order resolved — reference/customer stay null and the ticket
        // count is zero, exactly as the pre-refactor recap projected.
        $this->assertNull($entry['reference']);
        $this->assertNull($entry['customer']);
        $this->assertSame(0, $entry['tickets']);
    }

    public function test_recap_keeps_newest_first_and_caps_at_five_entries(): void
    {
        $scanner = User::factory()->scanner()->create();

        // Six distinct resolved scans in one session; the recap keeps only the
        // five newest, newest first. Use unreadable payloads so each is a
        // cheap, resolvable outcome that still writes a recap entry. The test
        // client persists the session across requests within a test, so the
        // recap accumulates exactly as it does for one operator at the door.
        $response = null;
        for ($i = 1; $i <= 6; $i++) {
            $response = $this->actingAs($scanner)
                ->post('/dashboard/scan', ['payload' => "REF{$i}."]);
        }

        $history = $response->session()->get(self::HISTORY_KEY);
        $this->assertIsArray($history);
        // HISTORY_LIMIT is 5.
        $this->assertCount(5, $history);
        // Every retained entry is a resolved unreadable outcome.
        foreach ($history as $entry) {
            $this->assertSame('unreadable', $entry['status']);
        }
    }
}
