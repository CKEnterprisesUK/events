<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\GdprService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for GDPR anonymisation retaining transactional records
 * (design Property 27).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the scoped UPDATE {@see GdprService::anonymise()}
 * issues behaves exactly as in production.
 *
 * Each iteration builds a random set of Orders for a target Customer within a
 * resolved Company — random counts, money figures, and statuses, each with a
 * random number of Ticket rows — plus a FOREIGN Company Order carrying the
 * SAME `customer_email`. It snapshots the transactional/financial fields (and
 * Ticket ids) of every Order, runs {@see GdprService::anonymise()} under the
 * resolved tenant, and asserts:
 *   - the Customer's identifiable personal fields (customer_name,
 *     customer_email) are scrubbed to the fixed placeholders on every matching
 *     Order; (Requirement 22.2)
 *   - every transactional/financial field is byte-for-byte unchanged
 *     (order_reference, ticket_subtotal_minor, booking_fee_minor,
 *     application_fee_minor, order_total_minor, status, Stripe ids); and every
 *     Ticket row still exists with the same id/ticket_type_id;
 *     (Requirements 22.1, 22.2)
 *   - the FOREIGN Company's identical-email Order is left completely
 *     unchanged — anonymise only ever touches the resolved tenant's own data.
 *     (Requirement 22.5, design Property 1)
 */
class GdprAnonymisationRetainsTransactionalRecordsTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 27: GDPR anonymisation retains transactional records — after the
     * scoped anonymise no identifiable personal data remains while the
     * transactional records are retained; another Company's identical-email
     * Order is untouched.
     *
     * **Validates: Requirements 22.1, 22.2**
     */
    // Feature: event-ticketing-platform, Property 27: GDPR anonymisation retains transactional records — after scoped delete/anonymise no identifiable personal data remains while transactional records are retained; export contains every stored personal field
    public function test_anonymise_scrubs_pii_while_retaining_transactional_records(): void
    {
        $service = app(GdprService::class);

        $statuses = [
            Order::STATUS_RESERVED,
            Order::STATUS_PAID,
            Order::STATUS_FREE_CONFIRMED,
            Order::STATUS_EXPIRED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_DISPUTED,
            Order::STATUS_VOIDED,
        ];

        $this->forAll(
            // Number of Orders the target Customer placed with the resolved
            // Company (1-4).
            Generator\choose(1, 4),
            // A pool of random money/status/ticket-count shapes, one entry per
            // possible Order. The body slices this to the chosen count.
            Generator\vector(4, Generator\tuple(
                // ticket_subtotal_minor
                Generator\choose(0, 100_000),
                // booking_fee_minor
                Generator\choose(0, 10_000),
                // application_fee_minor
                Generator\choose(0, 10_000),
                // status index into $statuses
                Generator\choose(0, count($statuses) - 1),
                // number of Ticket rows on the Order (0-3)
                Generator\choose(0, 3),
            )),
        )
            ->then(function (int $orderCount, array $shapePool) use ($service, $statuses): void {
                // Each iteration builds its OWN resolved + foreign Companies and
                // reasons only about those rows. RefreshDatabase wraps the test
                // in a transaction; DDL / TRUNCATE is banned. Per-iteration
                // scoped deletes at the end keep iterations independent.
                $targetEmail = 'customer@example.test';

                $resolved = Company::factory()->create();
                $foreign = Company::factory()->create();

                // --- Foreign Company Order with the SAME customer_email. It must
                // never be touched: it belongs to another tenant. ---
                app(TenantContext::class)->setCompany($foreign);
                $foreignEvent = Event::factory()->for($foreign)->create();
                $foreignOrder = Order::factory()->forEvent($foreignEvent)->create([
                    'customer_name' => 'Foreign Person',
                    'customer_email' => $targetEmail,
                    'status' => Order::STATUS_PAID,
                    'stripe_session_id' => 'cs_foreign_123',
                    'stripe_charge_id' => 'ch_foreign_123',
                    'stripe_payment_intent_id' => 'pi_foreign_123',
                ]);
                $foreignSnapshot = $this->snapshot($foreignOrder);

                // --- Resolved Company Orders for the target Customer. ---
                app(TenantContext::class)->setCompany($resolved);
                $resolvedEvent = Event::factory()->for($resolved)->create();

                $shapes = array_slice($shapePool, 0, $orderCount);

                /** @var array<int, array<string, mixed>> $snapshots keyed by order id */
                $snapshots = [];
                /** @var array<int, list<array{id:int, ticket_type_id:int, status:string}>> $ticketSnapshots keyed by order id */
                $ticketSnapshots = [];

                foreach ($shapes as $i => [$subtotal, $bookingFee, $appFee, $statusIndex, $ticketCount]) {
                    $total = $subtotal + $bookingFee;

                    $order = Order::factory()->forEvent($resolvedEvent)->create([
                        'customer_name' => 'Real Customer '.$i,
                        'customer_email' => $targetEmail,
                        'status' => $statuses[$statusIndex],
                        'ticket_subtotal_minor' => $subtotal,
                        'booking_fee_minor' => $bookingFee,
                        'application_fee_minor' => $appFee,
                        'order_total_minor' => $total,
                        'stripe_session_id' => 'cs_'.$i,
                        'stripe_charge_id' => 'ch_'.$i,
                        'stripe_payment_intent_id' => 'pi_'.$i,
                    ]);

                    $snapshots[$order->id] = $this->snapshot($order);

                    $rows = [];
                    for ($t = 0; $t < $ticketCount; $t++) {
                        $type = TicketType::factory()->forEvent($resolvedEvent)->create();
                        $ticket = Ticket::factory()->forOrder($order)->forTicketType($type)->create();
                        $rows[] = [
                            'id' => (int) $ticket->id,
                            'ticket_type_id' => (int) $ticket->ticket_type_id,
                            'status' => $ticket->status,
                        ];
                    }
                    // Stable ordering for comparison.
                    usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
                    $ticketSnapshots[$order->id] = $rows;
                }

                // --- Anonymise under the resolved tenant. ---
                $count = $service->anonymise($targetEmail);

                // Every matching Order for the resolved Company was anonymised.
                $this->assertSame(
                    $orderCount,
                    $count,
                    'anonymise must report the number of the resolved Company\'s matching Orders.'
                );

                // --- Assert each resolved Order: PII scrubbed, transactional
                // fields byte-for-byte unchanged, Ticket rows intact. ---
                foreach ($snapshots as $orderId => $before) {
                    $after = Order::withoutGlobalScopes()->findOrFail($orderId);

                    // PII scrubbed to the fixed placeholders. (Requirement 22.2)
                    $this->assertSame(
                        GdprService::ANONYMISED_NAME,
                        $after->customer_name,
                        "Order {$orderId} customer_name must be the anonymised placeholder."
                    );
                    $this->assertSame(
                        GdprService::ANONYMISED_EMAIL,
                        $after->customer_email,
                        "Order {$orderId} customer_email must be the anonymised placeholder."
                    );
                    // No identifiable personal data survives.
                    $this->assertNotSame($targetEmail, $after->customer_email);

                    // Every transactional/financial field is unchanged.
                    foreach ($before as $field => $value) {
                        $this->assertSame(
                            $value,
                            $after->getAttribute($field),
                            "Order {$orderId} transactional field {$field} must be retained unchanged."
                        );
                    }

                    // All Ticket rows still exist with the same id/ticket_type_id.
                    $actualRows = Ticket::withoutGlobalScopes()
                        ->where('order_id', $orderId)
                        ->orderBy('id')
                        ->get()
                        ->map(fn (Ticket $t): array => [
                            'id' => (int) $t->id,
                            'ticket_type_id' => (int) $t->ticket_type_id,
                            'status' => $t->status,
                        ])
                        ->all();

                    $this->assertSame(
                        $ticketSnapshots[$orderId],
                        $actualRows,
                        "Order {$orderId} Ticket rows must be retained unchanged after anonymise."
                    );
                }

                // --- Assert the foreign Company Order is completely unchanged. ---
                $foreignAfter = Order::withoutGlobalScopes()->findOrFail($foreignOrder->id);
                $this->assertSame(
                    'Foreign Person',
                    $foreignAfter->customer_name,
                    'Another Company\'s Order must not have its name anonymised.'
                );
                $this->assertSame(
                    $targetEmail,
                    $foreignAfter->customer_email,
                    'Another Company\'s identical-email Order must be left untouched.'
                );
                foreach ($foreignSnapshot as $field => $value) {
                    $this->assertSame(
                        $value,
                        $foreignAfter->getAttribute($field),
                        "Foreign Order transactional field {$field} must be untouched."
                    );
                }

                // Per-iteration cleanup — scoped deletes only, no DDL / TRUNCATE.
                app(TenantContext::class)->clear();
                Ticket::withoutGlobalScopes()->whereIn('order_id', array_keys($snapshots))->delete();
                Order::withoutGlobalScopes()
                    ->whereIn('id', array_merge(array_keys($snapshots), [$foreignOrder->id]))
                    ->delete();
            });
    }

    /**
     * Snapshot the transactional/financial fields that anonymise must retain
     * byte-for-byte: the Order reference, every money figure, the status, and
     * the Stripe identifiers.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Order $order): array
    {
        return [
            'order_reference' => $order->order_reference,
            'ticket_subtotal_minor' => $order->ticket_subtotal_minor,
            'booking_fee_minor' => $order->booking_fee_minor,
            'application_fee_minor' => $order->application_fee_minor,
            'order_total_minor' => $order->order_total_minor,
            'status' => $order->status,
            'stripe_session_id' => $order->stripe_session_id,
            'stripe_charge_id' => $order->stripe_charge_id,
            'stripe_payment_intent_id' => $order->stripe_payment_intent_id,
        ];
    }
}
