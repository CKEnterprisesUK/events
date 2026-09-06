<?php

namespace Tests\Feature;

use App\Jobs\SendTicketEmailJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature test for the manual ticket actions on the dashboard Order screen:
 *   - Download the confirmed Order's e-ticket as an A4 PDF.
 *   - Re-send the branded ticket email to the customer.
 *
 * Both are gated on ACTION_MANAGE_ORDERS, scoped to the acting Company (foreign
 * Orders 404), and only available for a confirmed (paid / free-confirmed) Order
 * — an unconfirmed Order has no valid QR to issue. The resend reuses the same
 * QR payload as the original send (a pure function of the order reference), so
 * a resent ticket scans identically.
 */
class OrderTicketDownloadAndResendTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A confirmed Order in the given Company with $qty tickets of one type.
     *
     * @return array{0: Order, 1: Event}
     */
    private function confirmedOrder(
        Company $company,
        int $qty = 2,
        string $status = Order::STATUS_PAID,
    ): array {
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => $qty,
            'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => $status,
            'fulfilled_at' => now(),
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $event];
    }

    public function test_admin_downloads_a_confirmed_orders_ticket_as_a_pdf(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        [$order] = $this->confirmedOrder($company, qty: 2);

        $response = $this->actingAs($admin)
            ->get("/dashboard/orders/{$order->id}/ticket.pdf");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString(
            'ticket-'.$order->order_reference.'.pdf',
            (string) $response->headers->get('content-disposition'),
        );
        // dompdf output always begins with the PDF magic bytes.
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_admin_resends_the_ticket_email_reusing_the_same_qr_payload(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        [$order] = $this->confirmedOrder($company, qty: 1, status: Order::STATUS_FREE_CONFIRMED);

        $expectedPayload = app(QrService::class)->payloadFor($order);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/resend")
            ->assertRedirect();

        Queue::assertPushed(SendTicketEmailJob::class, 1);
        Queue::assertPushed(
            SendTicketEmailJob::class,
            function (SendTicketEmailJob $job) use ($expectedPayload, $order): bool {
                // The job carries the order id + QR payload as private readonly
                // constructor args; read them via reflection to assert the
                // resend reuses the same (deterministic) QR as the original send.
                $props = (fn () => ['id' => $this->orderId, 'payload' => $this->qrPayload])
                    ->call($job);

                return $props['id'] === $order->getKey()
                    && $props['payload'] === $expectedPayload;
            },
        );
    }

    public function test_ticket_actions_are_unavailable_for_an_unconfirmed_order(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);

        [$order] = $this->confirmedOrder($company, qty: 1, status: Order::STATUS_RESERVED);

        $this->actingAs($admin)
            ->get("/dashboard/orders/{$order->id}/ticket.pdf")
            ->assertForbidden();

        $this->actingAs($admin)
            ->post("/dashboard/orders/{$order->id}/resend")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_scanner_cannot_download_or_resend_tickets(): void
    {
        Queue::fake();

        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);

        [$order] = $this->confirmedOrder($company, qty: 1);

        $this->actingAs($scanner)
            ->get("/dashboard/orders/{$order->id}/ticket.pdf")
            ->assertForbidden();

        $this->actingAs($scanner)
            ->post("/dashboard/orders/{$order->id}/resend")
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_cannot_download_another_companys_order_ticket(): void
    {
        $admin = User::factory()->admin()->create();
        $otherCompany = Company::factory()->create();

        [$order] = $this->confirmedOrder($otherCompany, qty: 1);

        $this->actingAs($admin)
            ->get("/dashboard/orders/{$order->id}/ticket.pdf")
            ->assertNotFound();
    }
}
