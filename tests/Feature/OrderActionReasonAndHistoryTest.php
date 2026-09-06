<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: an operator can attach a free-text reason when cancelling or
 * refunding an Order; the reason is stored in the audit trail alongside the
 * amount, actor and timestamp, and the Order's show page surfaces that history
 * so the "what happened, when and why" is visible on the booking.
 *
 * Stripe is mocked via the container-bound FakeStripePaymentService.
 */
class OrderActionReasonAndHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * A paid Order in the Company with $qty sold tickets, carrying a charge and
     * the given total.
     *
     * @return array{0: Order, 1: TicketType}
     */
    private function paidOrder(Company $company, int $totalMinor = 5_000, int $qty = 2): array
    {
        $company->stripe_account_id = 'acct_REASON';
        $company->save();

        $event = Event::factory()->for($company)->unlimitedCapacity()->create();

        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => $qty,
            'reserved_count' => 0,
        ]);

        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'fulfilled_at' => now(),
            'stripe_charge_id' => 'ch_reason',
            'order_total_minor' => $totalMinor,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type];
    }

    private function auditContext(int $orderId, string $action): array
    {
        /** @var AuditLog $row */
        $row = AuditLog::where('action', $action)
            ->where('auditable_id', $orderId)
            ->latest('id')
            ->firstOrFail();

        return $row->context ?? [];
    }

    public function test_full_refund_stores_the_reason_in_the_audit_context(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/refund", ['reason' => 'Event cancelled by organiser'])
            ->assertRedirect();

        $context = $this->auditContext($order->id, AuditLog::ORDER_REFUNDED);
        $this->assertSame('Event cancelled by organiser', $context['reason'] ?? null);
        $this->assertSame(5_000, $context['amount_minor'] ?? null);
    }

    public function test_partial_refund_stores_the_reason_in_the_audit_context(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", [
                'amount' => '15.00',
                'reason' => 'Refunded one of two tickets',
            ])
            ->assertRedirect();

        $context = $this->auditContext($order->id, AuditLog::ORDER_PARTIALLY_REFUNDED);
        $this->assertSame('Refunded one of two tickets', $context['reason'] ?? null);
        $this->assertSame(1_500, $context['amount_minor'] ?? null);
    }

    public function test_cancel_stores_the_reason_in_the_audit_context(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/cancel", ['reason' => 'Duplicate booking'])
            ->assertRedirect();

        $context = $this->auditContext($order->id, AuditLog::ORDER_CANCELLED);
        $this->assertSame('Duplicate booking', $context['reason'] ?? null);
    }

    public function test_a_blank_reason_is_not_stored(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/cancel", ['reason' => '   '])
            ->assertRedirect();

        $context = $this->auditContext($order->id, AuditLog::ORDER_CANCELLED);
        $this->assertArrayNotHasKey('reason', $context);
    }

    public function test_an_over_long_reason_is_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/cancel", ['reason' => str_repeat('x', 501)])
            ->assertSessionHasErrors('reason');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_order_show_page_renders_the_history_with_reason_and_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        // Perform a partial refund with a reason, then view the order.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", [
                'amount' => '20.00',
                'reason' => 'Goodwill gesture',
            ])
            ->assertRedirect();

        $response = $this->actingAs($admin)->get("/dashboard/orders/{$order->id}");

        $response->assertOk();
        $response->assertSee('History');
        $response->assertSee('Partially refunded an order');
        $response->assertSee('Goodwill gesture');
    }

    public function test_show_page_exposes_the_manage_order_popup_for_a_paid_order(): void
    {
        // The refund/cancel controls live in a pop-up so the screen stays clean:
        // the "Manage order" trigger and the modal scaffold are present, and the
        // three actions (partial refund, full refund, cancel) live inside it.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        $response = $this->actingAs($admin)->get("/dashboard/orders/{$order->id}");

        $response->assertOk();
        $response->assertSee('data-open-manage', false);
        $response->assertSee('data-manage-modal', false);
        $response->assertSee('Partial refund');
        $response->assertSee('Refund in full');
        $response->assertSee('Cancel order');
    }

    public function test_history_includes_a_webhook_refund_recorded_by_the_system(): void
    {
        // A system/webhook refund is recorded against the Order too, so it must
        // appear in the same history list (actor shown as System).
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company);

        app(\App\Services\AuditLogger::class)->recordSystem(
            action: AuditLog::WEBHOOK_REFUND_PROCESSED,
            auditable: $order,
            summary: 'Refund processed for order '.$order->order_reference,
            context: ['order_reference' => $order->order_reference],
        );

        $response = $this->actingAs($admin)->get("/dashboard/orders/{$order->id}");

        $response->assertOk();
        $response->assertSee('Refund processed');
        $response->assertSee('System');
    }
}
