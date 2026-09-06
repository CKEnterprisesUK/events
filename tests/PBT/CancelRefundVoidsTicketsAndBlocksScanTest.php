<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\OrderCancellationService;
use App\Services\QrService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for cancel/refund voiding tickets and blocking scan
 * (Property 25).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database so the OrderCancellationService's SELECT ... FOR UPDATE row
 * locking and capacity arithmetic behave exactly as in production. No table
 * DDL is performed — each iteration provisions its own Company/Event/
 * Ticket_Types/Order/Tickets and RefreshDatabase rolls them back.
 *
 * The invariant (task 20.2): for ANY confirmed Order, after ANY terminal
 * transition — dashboard cancel, dashboard refund, `charge.refunded` webhook,
 * or `charge.dispute.created` webhook — every one of the Order's Tickets is
 * voided (Ticket STATUS_VOIDED), the sold capacity the Order held is returned,
 * and a subsequent scan of the Order's QR is rejected as a failure that checks
 * nothing in (the Order is no longer confirmed, so `scanned_at` stays NULL).
 * This holds regardless of the Order's prior scan state and across random
 * transition types and random ticket quantities/types.
 * (Requirements 16.10, 17.3, 17.4, 17.5)
 */
class CancelRefundVoidsTicketsAndBlocksScanTest extends PbtTestCase
{
    use RefreshDatabase;

    /** The four terminal transitions that void an Order's tickets. */
    private const TRANSITIONS = ['cancel', 'refund', 'refund_webhook', 'dispute_webhook'];

    private function service(): OrderCancellationService
    {
        return app(OrderCancellationService::class);
    }

    /**
     * Property 25: Cancel/refund voids tickets and blocks scan — after a
     * confirmed Order undergoes any terminal transition (dashboard cancel,
     * dashboard refund, refund webhook, or dispute webhook) all of the Order's
     * Tickets are voided, the sold capacity it held is returned, and any
     * subsequent scan of its QR is rejected as a failure that checks nothing
     * in (scanned_at stays NULL) — regardless of the prior scan state.
     *
     * **Validates: Requirements 16.10, 17.3, 17.4, 17.5**
     */
    // Feature: event-ticketing-platform, Property 25: Cancel/refund voids tickets and blocks scan — after cancel/refund (incl. refund/dispute webhooks) all Tickets voided and any subsequent scan is rejected as failure
    public function test_terminal_transition_voids_all_tickets_returns_capacity_and_blocks_scan(): void
    {
        $qr = app(QrService::class);

        $this->forAll(
            // Which terminal transition to apply (index into TRANSITIONS).
            Generator\choose(0, count(self::TRANSITIONS) - 1),
            // Number of distinct Ticket_Types on the Order (1..3).
            Generator\choose(1, 3),
            // Per-Ticket_Type quantities (up to 3 entries; extras ignored).
            Generator\vector(3, Generator\choose(1, 5)),
            // Whether the Order was already scanned before the transition.
            Generator\elements(true, false),
            // Confirmed status flavour: paid or free-confirmed.
            Generator\elements(Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED),
        )
            ->then(function (
                int $transitionIndex,
                int $typeCount,
                array $quantities,
                bool $preScanned,
                string $confirmedStatus,
            ) use ($qr): void {
                $transition = self::TRANSITIONS[$transitionIndex];

                // ---- Provision a fresh, self-contained confirmed Order -------

                // A dedicated Company + Scanner per iteration so the scan runs
                // under this Order's tenant and cannot collide with other rows.
                $scanner = User::factory()->scanner()->create();
                $company = Company::find($scanner->company_id);

                // Unlimited overall capacity so the per-type sold baseline is
                // the only capacity we track and assert on.
                $event = Event::factory()->unlimitedCapacity()->for($company)->create();

                // One Ticket_Type per requested type, each carrying a sold
                // baseline equal to the quantity this Order holds against it —
                // exactly what a confirmed Order's fulfilment would have
                // committed to sold_count.
                $typeQuantities = array_slice($quantities, 0, $typeCount);

                $types = [];
                foreach ($typeQuantities as $i => $qty) {
                    $types[] = TicketType::factory()->forEvent($event)->create([
                        'name' => 'Type '.$i,
                        'sold_count' => $qty,
                        'reserved_count' => 0,
                    ]);
                }

                $order = Order::factory()->forEvent($event)->create([
                    'status' => $confirmedStatus,
                    'stripe_charge_id' => $confirmedStatus === Order::STATUS_PAID ? 'ch_'.uniqid() : null,
                    'scanned_at' => $preScanned ? now() : null,
                    'scanned_by' => $preScanned ? $scanner->id : null,
                ]);

                // The Company must carry a Stripe account id for a paid refund
                // path (the fake Stripe service records it, no network).
                $company->forceFill(['stripe_account_id' => 'acct_'.uniqid()])->save();

                // One Ticket row per unit, valid at the outset.
                $expectedTicketIds = [];
                foreach ($types as $i => $type) {
                    $qty = $typeQuantities[$i];
                    $created = Ticket::factory()
                        ->count($qty)
                        ->forOrder($order)
                        ->forTicketType($type)
                        ->create();
                    foreach ($created as $ticket) {
                        $expectedTicketIds[] = $ticket->id;
                    }
                }

                $order = $order->fresh();

                // Pre-condition sanity: a confirmed Order with all-valid tickets.
                $this->assertTrue($order->isConfirmed(), 'Order must start confirmed.');
                $validBefore = Ticket::withoutGlobalScopes()
                    ->where('order_id', $order->id)
                    ->where('status', Ticket::STATUS_VALID)
                    ->count();
                $this->assertSame(count($expectedTicketIds), $validBefore, 'All tickets valid before transition.');

                // ---- Apply the random terminal transition --------------------

                $service = $this->service();

                match ($transition) {
                    'cancel' => $service->cancel($order),
                    'refund' => $service->refund($order),
                    'refund_webhook' => $service->markRefundedFromWebhook($order),
                    'dispute_webhook' => $service->markDisputedFromWebhook($order),
                };

                // ---- Invariant 1: every ticket of the Order is voided --------
                // (Requirements 17.3, 17.4, 17.5)

                $notVoided = Ticket::withoutGlobalScopes()
                    ->where('order_id', $order->id)
                    ->where('status', '!=', Ticket::STATUS_VOIDED)
                    ->count();
                $this->assertSame(
                    0,
                    $notVoided,
                    "Transition [{$transition}] must void every one of the Order's tickets."
                );

                // The Order itself moved to a terminal status and is no longer
                // confirmed, so it cannot be checked in. (Requirement 16.10)
                $order = $order->fresh();
                $this->assertFalse(
                    $order->isConfirmed(),
                    "Transition [{$transition}] must leave the Order not confirmed."
                );

                // ---- Invariant 2: the sold capacity it held was returned -----
                // (Requirements 17.3, 17.4)

                foreach ($types as $i => $type) {
                    $this->assertSame(
                        0,
                        (int) $type->fresh()->sold_count,
                        "Transition [{$transition}] must return the sold capacity the Order held."
                    );
                }

                // ---- Invariant 3: a scan of the Order is blocked -------------
                // (Requirement 16.10)

                // A genuine, correctly-signed payload for this Order.
                $payload = $qr->payload($order->order_reference);

                $response = $this->actingAs($scanner)
                    ->post('/dashboard/scan', ['payload' => $payload]);

                $response->assertStatus(200);
                // The scanner reports the ticket is no longer valid: a failure,
                // never a check-in.
                $response->assertSee('no longer valid');
                $response->assertDontSee('Checked in');

                // The scan wrote no check-in: scanned_at is whatever it was
                // before the scan (never freshly set BY the blocked scan). A
                // pre-scanned Order keeps its prior timestamp; an un-scanned one
                // stays NULL. Either way the blocked scan changed nothing.
                $after = $order->fresh();
                if ($preScanned) {
                    $this->assertNotNull(
                        $after->scanned_at,
                        'A pre-scanned Order keeps its prior scanned_at.'
                    );
                } else {
                    $this->assertNull(
                        $after->scanned_at,
                        "Transition [{$transition}]: a blocked scan must not set scanned_at."
                    );
                }
            });
    }
}
