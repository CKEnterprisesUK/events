<?php

namespace Tests\Feature;

use App\Jobs\BackfillStripeFeesJob;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Services\Stripe\FakeStripePaymentService;
use App\Services\Stripe\StripePaymentService;
use App\Services\Stripe\StripeWebhookEvent;
use App\Services\WebhookProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers capturing the ACTUAL Stripe card-processing fee when a payment is
 * confirmed, and backfilling it later when Stripe had not settled the balance
 * transaction at webhook time. Stripe is mocked via the container-bound
 * {@see FakeStripePaymentService}, so no live call and no card data are
 * involved. (Truthful-payout feature)
 */
class StripeFeeCaptureTest extends TestCase
{
    use RefreshDatabase;

    private function fakeStripe(): FakeStripePaymentService
    {
        /** @var FakeStripePaymentService $fake */
        $fake = app(StripePaymentService::class);

        return $fake;
    }

    /**
     * A reserved paid-flow Order on a Company with a connected Stripe account,
     * carrying the session + payment-intent ids a completed Checkout Session
     * would reference.
     */
    private function reservedPaidOrder(string $sessionId, string $paymentIntentId): Order
    {
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_FEE',
            'stripe_charges_enabled' => true,
        ]);
        $event = Event::factory()->for($company)->create();

        return Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_RESERVED,
            'ticket_subtotal_minor' => 10_000,
            'application_fee_minor' => 500,
            'order_total_minor' => 10_000,
            'stripe_session_id' => $sessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
        ]);
    }

    public function test_actual_stripe_fee_is_captured_on_payment_confirmation(): void
    {
        $order = $this->reservedPaidOrder('cs_fee_1', 'pi_fee_1');

        // Arrange the fee Stripe reports for this payment intent.
        $this->fakeStripe()->setChargeFee('pi_fee_1', feeMinor: 170, chargeId: 'ch_fee_1');

        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_fee_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_fee_1', 'payment_intent' => 'pi_fee_1'],
        ));

        $order->refresh();

        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(170, $order->stripe_fee_minor);
        $this->assertSame('ch_fee_1', $order->stripe_charge_id);
        // Net payout subtracts BOTH the platform fee and the Stripe fee.
        $this->assertSame(10_000 - 500 - 170, $order->netPayoutMinor());
    }

    public function test_fee_is_left_unset_when_stripe_has_not_settled_yet(): void
    {
        $order = $this->reservedPaidOrder('cs_fee_2', 'pi_fee_2');

        // No arranged fee => the fake reports null (charge not settled yet).
        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_fee_2',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_fee_2', 'payment_intent' => 'pi_fee_2'],
        ));

        $order->refresh();

        // Payment still confirmed; fee simply not captured (null), not zero, so
        // a later backfill can fill it in without ever recording a wrong value.
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNull($order->stripe_fee_minor);
    }

    public function test_free_order_captures_no_stripe_fee(): void
    {
        $company = Company::factory()->create([
            'stripe_account_id' => 'acct_FREE',
            'stripe_charges_enabled' => true,
        ]);
        $event = Event::factory()->for($company)->create();
        $order = Order::factory()->forEvent($event)->free()->create([
            'status' => Order::STATUS_RESERVED,
            'stripe_session_id' => 'cs_free_1',
        ]);

        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_free_1',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_free_1'],
        ));

        $order->refresh();

        // A free order is never charged, so no fee lookup happened and none is set.
        $this->assertNull($order->stripe_fee_minor);
        $this->assertSame([], $this->fakeStripe()->chargeFeeCalls);
    }

    public function test_backfill_job_captures_missing_fee_once_stripe_settles(): void
    {
        $order = $this->reservedPaidOrder('cs_fee_3', 'pi_fee_3');

        // First confirmation: Stripe has not settled, so the fee is left unset.
        app(WebhookProcessor::class)->process(new StripeWebhookEvent(
            id: 'evt_fee_3',
            type: 'checkout.session.completed',
            data: ['id' => 'cs_fee_3', 'payment_intent' => 'pi_fee_3'],
        ));

        $this->assertNull($order->fresh()->stripe_fee_minor);

        // Later, Stripe settles and the balance transaction fee is available.
        $this->fakeStripe()->setChargeFee('pi_fee_3', feeMinor: 165, chargeId: 'ch_fee_3');

        $captured = app(BackfillStripeFeesJob::class)->handle($this->fakeStripe());

        $this->assertSame(1, $captured);
        $this->assertSame(165, $order->fresh()->stripe_fee_minor);
    }

    public function test_backfill_job_skips_orders_that_already_have_a_fee(): void
    {
        $order = $this->reservedPaidOrder('cs_fee_4', 'pi_fee_4');
        Order::withoutGlobalScopes()->whereKey($order->getKey())->update([
            'status' => Order::STATUS_PAID,
            'stripe_fee_minor' => 140,
        ]);

        // Arrange a different fee; the job must NOT overwrite the captured value.
        $this->fakeStripe()->setChargeFee('pi_fee_4', feeMinor: 999);

        $captured = app(BackfillStripeFeesJob::class)->handle($this->fakeStripe());

        $this->assertSame(0, $captured);
        $this->assertSame(140, $order->fresh()->stripe_fee_minor);
    }
}
