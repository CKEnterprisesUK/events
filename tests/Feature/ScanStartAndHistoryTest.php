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
use Tests\TestCase;

/**
 * Covers the scanner's intermediary start page and the session-backed
 * recent-scan recap.
 *
 * The start page (dashboard.scan.index) is a camera-free landing that offers a
 * "start scanning" action and shows the last few scans. The live scanner
 * (dashboard.scan.live) opens the camera. Each POST to dashboard.scan.submit
 * records its outcome in a small, per-session rolling history (no DB table),
 * capped at five entries, which both pages render.
 */
class ScanStartAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private QrService $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrService::class);
    }

    /**
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

    public function test_start_page_offers_start_scanning_and_has_no_camera(): void
    {
        $scanner = User::factory()->scanner()->create();

        $response = $this->actingAs($scanner)->get('/dashboard/scan');

        $response->assertStatus(200);
        // Offers the action into the live scanner.
        $response->assertSee(route('dashboard.scan.live'), false);
        $response->assertSee('Start scanning');
        // It is a camera-free intermediary — no viewfinder or decoder here.
        $response->assertDontSee('id="scanner-video"', false);
        $response->assertDontSee('getUserMedia', false);
    }

    public function test_scan_is_recorded_in_recent_history_and_shown_again(): void
    {
        $scanner = User::factory()->scanner()->create();
        $order = $this->confirmedOrderFor($scanner, ['VIP' => 2]);
        $payload = $this->qr->payload($order->order_reference);

        // A successful scan is recorded in the session recap and rendered back
        // on the live page immediately.
        $response = $this->actingAs($scanner)->post('/dashboard/scan', [
            'payload' => $payload,
        ]);

        $response->assertStatus(200);
        $response->assertSee('Recent scans');
        $response->assertSee($order->order_reference);

        // The recap survives to the start page (same session).
        $this->actingAs($scanner)->get('/dashboard/scan')
            ->assertSee('Recent scans')
            ->assertSee($order->order_reference);
    }

    public function test_history_is_capped_at_five_newest_first(): void
    {
        $scanner = User::factory()->scanner()->create();

        // Six distinct orders scanned in sequence; only the newest five are
        // kept, newest first.
        $references = [];
        for ($i = 0; $i < 6; $i++) {
            $order = $this->confirmedOrderFor($scanner);
            $references[] = $order->order_reference;
            $this->actingAs($scanner)->post('/dashboard/scan', [
                'payload' => $this->qr->payload($order->order_reference),
            ]);
        }

        $response = $this->actingAs($scanner)->get('/dashboard/scan');

        // The oldest (first) reference has fallen off the five-entry cap.
        $response->assertDontSee($references[0]);
        // The five newer ones remain.
        for ($i = 1; $i < 6; $i++) {
            $response->assertSee($references[$i]);
        }
    }
}
