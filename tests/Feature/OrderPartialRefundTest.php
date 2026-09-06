<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: partial refunds of a paid Order.
 *
 * A paid Order can be refunded one or more times for part of its total. Each
 * partial refund issues a Stripe refund on the Company's connected account
 * (mocked) for the chosen amount and adds it to the Order's cumulative
 * `refunded_total_minor`, while the Order stays `paid` and its Tickets stay
 * valid. Only once cumulative refunds reach the full order total does the Order
 * flip to the terminal `refunded` state — voiding its Tickets and returning its
 * sold capacity via the same path a full refund uses. (Requirement 17.2)
 *
 * Stripe is always mocked via the container-bound FakeStripePaymentService; no
 * live API and no card data are involved.
 */
class OrderPartialRefundTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * A paid Order in the given Company with $qty tickets of one Ticket_Type
     * whose capacity is committed to `sold_count`, carrying a Stripe charge and
     * the given total, ready to be (partially) refunded.
     *
     * @return array{0: Order, 1: TicketType}
     */
    private function paidOrder(Company $company, int $totalMinor, int $qty = 2): array
    {
        $company->stripe_account_id = 'acct_PARTIAL';
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
            'stripe_charge_id' => 'ch_partial',
            'order_total_minor' => $totalMinor,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type];
    }

    private function validTicketCount(Order $order): int
    {
        return Ticket::withoutGlobalScopes()
            ->where('order_id', $order->getKey())
            ->where('status', Ticket::STATUS_VALID)
            ->count();
    }

    public function test_partial_refund_issues_a_stripe_refund_for_the_amount_and_keeps_the_order_paid(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order, $type] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '20.00'])
            ->assertRedirect();

        // Stripe refund issued for exactly the partial amount.
        $refunds = $this->fakeStripe()->refundCalls;
        $this->assertCount(1, $refunds);
        $this->assertSame('acct_PARTIAL', $refunds[0]['connected_account_id']);
        $this->assertSame('ch_partial', $refunds[0]['charge_id']);
        $this->assertSame(2_000, $refunds[0]['amount_minor']);

        // Order stays paid, cumulative refund recorded, tickets still valid,
        // capacity unchanged.
        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(2_000, $order->refunded_total_minor);
        $this->assertSame(3_000, $order->refundableRemainingMinor());
        $this->assertSame(2, $this->validTicketCount($order));
        $this->assertSame(2, $type->fresh()->sold_count);
    }

    public function test_multiple_partial_refunds_accumulate_and_the_final_one_fully_refunds_the_order(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order, $type] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        // First partial: 20.00 of 50.00.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '20.00'])
            ->assertRedirect();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);

        // Second partial: the remaining 30.00 → cumulative reaches the total, so
        // the Order flips to terminal refunded, tickets void, capacity returns.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '30.00'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertSame(5_000, $order->refunded_total_minor);
        $this->assertSame(0, $order->refundableRemainingMinor());
        $this->assertSame(0, $this->validTicketCount($order), 'Tickets void once fully refunded.');
        $this->assertSame(0, $type->fresh()->sold_count, 'Capacity returned once fully refunded.');

        // Two Stripe refunds, for the two amounts.
        $refunds = $this->fakeStripe()->refundCalls;
        $this->assertCount(2, $refunds);
        $this->assertSame(2_000, $refunds[0]['amount_minor']);
        $this->assertSame(3_000, $refunds[1]['amount_minor']);
    }

    public function test_partial_refund_exceeding_the_remaining_balance_is_rejected_with_no_stripe_call(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order, $type] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        // 60.00 > 50.00 total → validation error, nothing refunded.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '60.00'])
            ->assertRedirect("/dashboard/orders/{$order->id}")
            ->assertSessionHasErrors('amount');

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(0, $order->refunded_total_minor);
        $this->assertSame(2, $type->fresh()->sold_count);
        $this->assertCount(0, $this->fakeStripe()->refundCalls);
    }

    public function test_partial_refund_cannot_exceed_remaining_after_a_prior_partial(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        // Refund 40.00, leaving 10.00.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '40.00'])
            ->assertRedirect();

        // A second refund of 20.00 now exceeds the 10.00 remaining → rejected.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '20.00'])
            ->assertSessionHasErrors('amount');

        $order->refresh();
        $this->assertSame(4_000, $order->refunded_total_minor, 'The second, over-large refund left the total unchanged.');
        $this->assertCount(1, $this->fakeStripe()->refundCalls);
    }

    public function test_full_refund_after_a_partial_only_refunds_the_remaining_balance(): void
    {
        // The full "Refund" button on an already-partially-refunded Order must
        // refund only what is still outstanding, never the whole total again.
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order, $type] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        // Partial 30.00 first.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '30.00'])
            ->assertRedirect();

        // Now the full refund: should refund the remaining 20.00 only.
        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(Order::STATUS_REFUNDED, $order->status);
        $this->assertSame(5_000, $order->refunded_total_minor);
        $this->assertSame(0, $type->fresh()->sold_count);

        $refunds = $this->fakeStripe()->refundCalls;
        $this->assertCount(2, $refunds);
        $this->assertSame(3_000, $refunds[0]['amount_minor']);
        $this->assertSame(2_000, $refunds[1]['amount_minor'], 'Full refund tops up only the outstanding balance.');
    }

    public function test_partial_refund_records_an_audit_row(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '15.00'])
            ->assertRedirect();

        $this->assertSame(
            1,
            AuditLog::where('action', AuditLog::ORDER_PARTIALLY_REFUNDED)
                ->where('auditable_id', $order->id)
                ->count(),
        );
    }

    public function test_non_admin_cannot_partially_refund(): void
    {
        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);
        [$order, $type] = $this->paidOrder($company, totalMinor: 5_000, qty: 2);

        $this->actingAs($scanner)
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '10.00'])
            ->assertForbidden();

        $order->refresh();
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(0, $order->refunded_total_minor);
        $this->assertCount(0, $this->fakeStripe()->refundCalls);
    }

    public function test_service_rejects_a_zero_or_negative_amount(): void
    {
        $company = Company::factory()->create();
        [$order] = $this->paidOrder($company, totalMinor: 5_000, qty: 1);

        $service = app(OrderCancellationService::class);

        $this->expectException(\InvalidArgumentException::class);
        $service->partialRefund($order, 0);
    }
}
