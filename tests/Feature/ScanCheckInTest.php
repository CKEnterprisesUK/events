<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 19.1 — the web QR scanner (ScanController + scanner page) and the
 * atomic single check-in.
 *
 * Requirements: 16.1/16.2 (phone-browser camera page + camera-required
 * message), 16.3 (unreadable code), 16.4/16.5 (HMAC recompute + invalid code),
 * 16.6 (foreign-Company Order rejected), 16.7 (full Order breakdown), 16.8
 * (atomic single check-in), 16.9 (already-scanned + prior scanned_at), 16.10
 * (voided/unconfirmed failure). Scanner-role gating (3.6, 3.7) is exercised
 * alongside.
 */
class ScanCheckInTest extends TestCase
{
    use RefreshDatabase;

    private QrService $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrService::class);
    }

    /**
     * A confirmed, unscanned Order belonging to the given Scanner's Company,
     * with `$quantities` tickets per Ticket_Type name.
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

    // ---- Scanner page (16.1, 16.2) ------------------------------------------

    public function test_scanner_page_loads_with_camera_and_permission_markup(): void
    {
        $scanner = User::factory()->scanner()->create();

        $response = $this->actingAs($scanner)->get('/dashboard/scan');

        $response->assertStatus(200);
        // 16.1 — a phone-browser camera surface (no app install).
        $response->assertSee('scanner-video', false);
        $response->assertSee('getUserMedia', false);
        // 16.2 — a camera-access-required message is present on the page.
        $response->assertSee('Camera access is required', false);
    }

    // ---- Decode / verify rejections (16.3, 16.4, 16.5) ----------------------

    public function test_unreadable_code_is_rejected(): void
    {
        $scanner = User::factory()->scanner()->create();

        // 16.3 — a payload that is not `reference.token` cannot be decoded.
        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => 'not-a-valid-payload',
        ]);

        $response->assertStatus(200);
        $response->assertSee('Unreadable code');
    }

    public function test_tampered_token_is_rejected_as_invalid(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner);

        // A well-shaped payload whose token is not the real HMAC. 16.4/16.5 —
        // recomputing the HMAC over the reference detects the tamper.
        $tampered = $order->order_reference.'.'.str_repeat('0', 64);

        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $tampered,
        ]);

        $response->assertStatus(200);
        $response->assertSee('Invalid code');

        // The Order is left unscanned.
        $this->assertNull($order->fresh()->scanned_at);
    }

    // ---- Tenant scoping (16.6) ----------------------------------------------

    public function test_order_of_another_company_is_rejected(): void
    {
        $scanner = User::factory()->scanner()->create();

        // A confirmed Order belonging to a *different* Company.
        $otherEvent = Event::factory()->create();
        $foreignOrder = Order::factory()->forEvent($otherEvent)->create([
            'status' => Order::STATUS_PAID,
        ]);

        // The payload is a genuine, correctly-signed payload for the foreign
        // Order — only the tenant scope keeps it out.
        $payload = $this->qr->payload($foreignOrder->order_reference);

        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $payload,
        ]);

        $response->assertStatus(200);
        // 16.6 — foreign Order is not found under the scanning Company's scope.
        $response->assertSee('does not belong to your organisation');

        // Untouched.
        $this->assertNull($foreignOrder->fresh()->scanned_at);
    }

    // ---- Voided / unconfirmed failure (16.10) -------------------------------

    public function test_voided_order_is_rejected_with_failure(): void
    {
        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);
        $event = Event::factory()->for($company)->create();

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_VOIDED,
        ]);

        $payload = $this->qr->payload($order->order_reference);

        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $payload,
        ]);

        $response->assertStatus(200);
        // 16.10 — a voided Order fails at scan and is not checked in.
        $response->assertSee('no longer valid');
        $this->assertNull($order->fresh()->scanned_at);
    }

    public function test_reserved_order_is_rejected_with_failure(): void
    {
        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);
        $event = Event::factory()->for($company)->create();

        // A not-yet-confirmed (reserved) Order must not be checkable in.
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
        ]);

        $payload = $this->qr->payload($order->order_reference);

        $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $payload,
        ])->assertSee('no longer valid');

        $this->assertNull($order->fresh()->scanned_at);
    }

    // ---- Valid check-in + breakdown (16.7, 16.8) ----------------------------

    public function test_valid_unscanned_order_checks_in_once_and_shows_breakdown(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner, ['VIP' => 1, 'General' => 3]);

        $payload = $this->qr->payload($order->order_reference);

        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $payload,
        ]);

        $response->assertStatus(200);
        $response->assertSee('Checked in');

        // 16.7 — the full breakdown of Ticket_Types and quantities.
        $response->assertSee('VIP');
        $response->assertSee('General');

        // 16.8 — scanned_at and scanned_by recorded exactly once.
        $fresh = $order->fresh();
        $this->assertNotNull($fresh->scanned_at);
        $this->assertSame($scanner->id, $fresh->scanned_by);
    }

    public function test_second_scan_reports_already_scanned_with_prior_timestamp(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner);
        $payload = $this->qr->payload($order->order_reference);

        // First scan checks in.
        $this->actingAs($scanner)->post('/dashboard/scan', ['payload' => $payload])
            ->assertSee('Checked in');

        $firstScannedAt = $order->fresh()->scanned_at;

        // 16.9 — a second scan reports already-scanned with the prior time.
        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $payload,
        ]);

        $response->assertStatus(200);
        $response->assertSee('Already scanned');
        $response->assertSee($firstScannedAt->format('Y-m-d H:i:s'));

        // The recorded scanned_at never changes on a re-scan.
        $this->assertEquals(
            $firstScannedAt->toDateTimeString(),
            $order->fresh()->scanned_at->toDateTimeString()
        );
    }

    // ---- Atomic single-scan guard (16.8) ------------------------------------

    public function test_check_in_update_is_atomic_single_scan(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner);

        // The guarded UPDATE writes exactly one row on the first application and
        // zero on any subsequent one — the concurrency guarantee behind 16.8.
        $first = DB::table('orders')
            ->where('id', $order->id)
            ->whereNull('scanned_at')
            ->update(['scanned_at' => now(), 'scanned_by' => $scanner->id]);

        $second = DB::table('orders')
            ->where('id', $order->id)
            ->whereNull('scanned_at')
            ->update(['scanned_at' => now(), 'scanned_by' => $scanner->id]);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
    }

    // ---- Role gating (3.6, 3.7) ---------------------------------------------

    public function test_non_scanner_roles_cannot_reach_the_scanner(): void
    {
        foreach ([
            User::factory()->owner()->create(),
            User::factory()->admin()->create(),
            User::factory()->accountant()->create(),
        ] as $user) {
            // 3.6/3.7 — check-in is the Scanner's action only.
            $this->actingAs($user)->get('/dashboard/scan')->assertForbidden();

            $order = $this->confirmedOrderFor(
                User::factory()->scanner()->create(['company_id' => $user->company_id])
            );
            $payload = $this->qr->payload($order->order_reference);

            $this->actingAs($user)->post('/dashboard/scan', ['payload' => $payload])
                ->assertForbidden();

            // A denied action leaves the Order unscanned.
            $this->assertNull($order->fresh()->scanned_at);
        }
    }
}
