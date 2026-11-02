<?php

namespace Tests\Feature;

use App\Jobs\SendRefundEmailJob;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature: the customer refund confirmation email.
 *
 * When an Order is refunded (full or partial) from the dashboard, a branded
 * refund confirmation email is enqueued to the customer stating the amount
 * refunded and the up-to-7-working-days bank timing. An organiser can also
 * re-send that confirmation on demand from the Order screen for any Order that
 * carries a refund. The resend is gated on the refund permission; an Order with
 * no refund has nothing to confirm.
 *
 * The email is queued, so the HTTP tests fake the queue and assert the
 * SendRefundEmailJob is pushed with the right amount / fully-refunded flag; the
 * job carries those as private readonly constructor args, read via reflection.
 */
class OrderRefundEmailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A paid Order carrying a Stripe charge with the given total, ready to be
     * (partially) refunded.
     *
     * @return array{0: Order, 1: TicketType}
     */
    private function paidOrder(Company $company, int $totalMinor, int $qty = 2): array
    {
        $company->stripe_account_id = 'acct_REFUND_EMAIL';
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
            'stripe_charge_id' => 'ch_refund_email',
            'order_total_minor' => $totalMinor,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return [$order, $type];
    }

    /**
     * Read a private readonly constructor arg off the dispatched job.
     */
    private function jobArg(SendRefundEmailJob $job, string $name): mixed
    {
        $prop = (new \ReflectionObject($job))->getProperty($name);
        $prop->setAccessible(true);

        return $prop->getValue($job);
    }

    public function test_full_refund_enqueues_a_fully_refunded_confirmation_email(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/refund")
            ->assertRedirect();

        Queue::assertPushed(SendRefundEmailJob::class, 1);
        Queue::assertPushed(
            SendRefundEmailJob::class,
            function (SendRefundEmailJob $job) use ($order): bool {
                return $this->jobArg($job, 'orderId') === $order->id
                    && $this->jobArg($job, 'amountMinor') === 5_000
                    && $this->jobArg($job, 'fullyRefunded') === true;
            },
        );
    }

    public function test_partial_refund_enqueues_a_partial_confirmation_email_for_the_amount(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000);

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/partial-refund", ['amount' => '20.00'])
            ->assertRedirect();

        Queue::assertPushed(
            SendRefundEmailJob::class,
            function (SendRefundEmailJob $job) use ($order): bool {
                return $this->jobArg($job, 'orderId') === $order->id
                    && $this->jobArg($job, 'amountMinor') === 2_000
                    && $this->jobArg($job, 'fullyRefunded') === false;
            },
        );
    }

    public function test_admin_resends_the_refund_confirmation_for_a_refunded_order(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000);

        // A standing partial refund of £30 recorded against the order.
        $order->refunded_total_minor = 3_000;
        $order->save();

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/resend-refund")
            ->assertRedirect();

        // The resend re-states the cumulative refunded total and the current
        // fully-refunded state (still partial here).
        Queue::assertPushed(
            SendRefundEmailJob::class,
            function (SendRefundEmailJob $job) use ($order): bool {
                return $this->jobArg($job, 'orderId') === $order->id
                    && $this->jobArg($job, 'amountMinor') === 3_000
                    && $this->jobArg($job, 'fullyRefunded') === false;
            },
        );

        // The resend is recorded in the audit trail.
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ORDER_REFUND_EMAIL_RESENT,
            'auditable_id' => $order->id,
        ]);
    }

    public function test_resend_is_refused_for_an_order_with_no_refund(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000); // refunded_total_minor = 0

        $this->actingAs($admin)
            ->from("/dashboard/orders/{$order->id}")
            ->post("/dashboard/orders/{$order->id}/resend-refund")
            ->assertForbidden();

        Queue::assertNotPushed(SendRefundEmailJob::class);
    }

    public function test_a_scanner_cannot_resend_the_refund_confirmation(): void
    {
        Queue::fake();

        $scanner = User::factory()->scanner()->create();
        $company = Company::find($scanner->company_id);
        [$order] = $this->paidOrder($company, totalMinor: 5_000);
        $order->refunded_total_minor = 5_000;
        $order->status = Order::STATUS_REFUNDED;
        $order->save();

        $this->actingAs($scanner)
            ->post("/dashboard/orders/{$order->id}/resend-refund")
            ->assertForbidden();

        Queue::assertNotPushed(SendRefundEmailJob::class);
    }
}
