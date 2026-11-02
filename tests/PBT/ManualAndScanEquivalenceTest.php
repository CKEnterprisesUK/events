<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Operator;
use App\Services\QrService;
use App\Services\ScanResult;
use App\Services\TenantContext;
use App\Services\TicketCheckInService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for manual/scan equivalence (design Property 3, task 4.5).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the tenant-scoped load and the guarded single
 * check-in behave exactly as in production.
 *
 * The property, per Requirements 6.1 and 5.8:
 *   The two ways a door operator can present a confirmed, unscanned Order to
 *   {@see TicketCheckInService::resolve()} — typing its Order_Reference by hand
 *   (in ANY letter case) or scanning its QR_Payload — are equivalent. Both
 *   resolve to a `checked_in` Scan_Outcome carrying the SAME Ticket_Type +
 *   quantity breakdown.
 *
 * Why two Orders per iteration:
 *   A single Order can only be checked in once — the first resolve mutates its
 *   state (5.8), so the second path would see `already_scanned` rather than
 *   `checked_in`. To compare the two paths cleanly, each iteration builds TWO
 *   Orders with an IDENTICAL Ticket_Type composition in the same Company/Event:
 *   the manual path checks in one, the scan path checks in the other, and the
 *   two resulting breakdowns are asserted equal. Because the composition is
 *   identical, equal breakdowns prove the manual and scanned inputs are
 *   interchangeable.
 *
 * The manual reference is submitted in a RANDOM letter-casing (upper, lower, or
 * mixed) to exercise the service's case normalisation (6.1): however the
 * operator types it, it must resolve to the same `checked_in` outcome as the
 * scanned payload.
 */
class ManualAndScanEquivalenceTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 3: Manual reference and scanned payload are equivalent — for a
     * confirmed, unscanned Order, submitting its Order_Reference manually (any
     * letter case) and submitting its QR_Payload both yield `checked_in` with
     * the same Ticket_Type + quantity breakdown.
     *
     * **Validates: Requirements 6.1, 5.8**
     */
    // Feature: ios-scanner-app, Property 3: Manual reference and scanned payload are equivalent — a manually typed Order_Reference (any case) and its QR_Payload both check in with the same breakdown
    public function test_manual_reference_and_scanned_payload_both_check_in_with_the_same_breakdown(): void
    {
        $qr = app(QrService::class);
        $service = app(TicketCheckInService::class);

        $this->forAll(
            // The Ticket_Type composition shared by both equivalent Orders:
            // 1-3 named types, each carrying 1-4 Tickets. Distinct names keep
            // the breakdown rows unambiguous.
            Generator\associative([
                'General' => Generator\choose(1, 4),
                'VIP' => Generator\choose(0, 4),
                'Early Bird' => Generator\choose(0, 4),
            ]),
            // Both paid and free-confirmed Orders are valid, scannable Orders.
            Generator\elements(Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED),
            // How the operator types the manual reference: as stored (upper),
            // all lower, or alternating case. All three must behave identically.
            Generator\elements('upper', 'lower', 'mixed'),
        )
            ->then(function (array $composition, string $confirmedStatus, string $casing) use ($qr, $service): void {
                // Drop zero-quantity types so every type in the composition
                // contributes a breakdown row; guarantee at least one Ticket.
                $composition = array_filter($composition, static fn (int $qty): bool => $qty > 0);
                if ($composition === []) {
                    $composition = ['General' => 1];
                }

                // Each iteration builds its OWN Company/Event and reasons only
                // about those rows. RefreshDatabase wraps the test in a
                // transaction; no DDL / TRUNCATE is used.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->create();
                $operatorUser = User::factory()->scanner()->for($company)->create();
                $operator = Operator::fromUser($operatorUser);

                // The check-in loads the Order through the tenant scope, so bind
                // the Company on the shared request-scoped context.
                app(TenantContext::class)->setCompany($company);

                // Two confirmed, unscanned Orders with the SAME Ticket_Type
                // composition: one for the manual path, one for the scan path.
                $manualOrder = $this->confirmedOrder($event, $confirmedStatus, $composition);
                $scanOrder = $this->confirmedOrder($event, $confirmedStatus, $composition);

                // Manual path: type the Order_Reference in the generated casing
                // (no `.` -> treated as a manual reference, normalised upstream).
                $typedReference = $this->applyCasing($manualOrder->order_reference, $casing);
                $manualResult = $service->resolve($typedReference, $operator);

                // Scan path: the QR payload the scanner decodes for the other
                // (identically composed) Order.
                $scanPayload = $qr->payload($scanOrder->order_reference);
                $scanResult = $service->resolve($scanPayload, $operator);

                // Both paths yield `checked_in`. (Requirements 6.1, 5.8)
                $this->assertSame(
                    ScanResult::STATUS_CHECKED_IN,
                    $manualResult->status,
                    'A manually typed Order_Reference (any case) of a confirmed, unscanned Order must check in.'
                );
                $this->assertSame(
                    ScanResult::STATUS_CHECKED_IN,
                    $scanResult->status,
                    'A scanned QR_Payload of a confirmed, unscanned Order must check in.'
                );

                // Both carry the SAME breakdown. The breakdown is an ordered
                // list of {name, quantity} rows (ordered by Ticket_Type name in
                // the service), so identical compositions must produce identical
                // lists. (Requirements 6.1, 5.8)
                $this->assertSame(
                    $this->expectedBreakdown($composition),
                    $manualResult->breakdown,
                    'The manual-path breakdown must reflect the Order composition.'
                );
                $this->assertSame(
                    $manualResult->breakdown,
                    $scanResult->breakdown,
                    'The manual reference and the scanned payload must yield the same breakdown.'
                );

                // Each path mutated its own Order exactly once, confirming the
                // `checked_in` outcome reflects a real single check-in (5.8).
                $this->assertNotNull($manualOrder->fresh()->scanned_at);
                $this->assertSame($operatorUser->getKey(), (int) $manualOrder->fresh()->scanned_by);
                $this->assertNotNull($scanOrder->fresh()->scanned_at);
                $this->assertSame($operatorUser->getKey(), (int) $scanOrder->fresh()->scanned_by);

                // Per-iteration cleanup keeps iterations independent without any
                // DDL / TRUNCATE — plain scoped deletes only.
                app(TenantContext::class)->clear();
                Ticket::withoutGlobalScopes()
                    ->whereIn('order_id', [$manualOrder->getKey(), $scanOrder->getKey()])
                    ->delete();
                Order::withoutGlobalScopes()
                    ->whereIn('id', [$manualOrder->getKey(), $scanOrder->getKey()])
                    ->delete();
                User::withoutGlobalScopes()->whereKey($operatorUser->getKey())->delete();
            });
    }

    /**
     * A confirmed, unscanned Order on the given Event carrying `$composition`
     * Tickets per Ticket_Type name.
     *
     * @param  array<string, int>  $composition
     */
    private function confirmedOrder(Event $event, string $confirmedStatus, array $composition): Order
    {
        $order = Order::factory()->forEvent($event)->create([
            'status' => $confirmedStatus,
            'scanned_at' => null,
            'scanned_by' => null,
        ]);

        foreach ($composition as $name => $count) {
            $type = TicketType::factory()->forEvent($event)->create(['name' => $name]);

            Ticket::factory()
                ->count($count)
                ->forOrder($order)
                ->forTicketType($type)
                ->create();
        }

        return $order->fresh();
    }

    /**
     * The breakdown the service must produce for a composition: one row per
     * Ticket_Type name with its quantity, ordered by name (the service orders
     * by `ticket_types.name`).
     *
     * @param  array<string, int>  $composition
     * @return list<array{name: string, quantity: int}>
     */
    private function expectedBreakdown(array $composition): array
    {
        ksort($composition);

        $rows = [];
        foreach ($composition as $name => $quantity) {
            $rows[] = ['name' => $name, 'quantity' => $quantity];
        }

        return $rows;
    }

    /**
     * Re-case a stored (uppercase) Order_Reference the way an operator might
     * type it: as stored, all lowercase, or alternating case. The service
     * normalises to uppercase before lookup, so every variant must resolve the
     * same Order. (Requirement 6.1)
     */
    private function applyCasing(string $reference, string $casing): string
    {
        return match ($casing) {
            'lower' => strtolower($reference),
            'mixed' => $this->alternatingCase($reference),
            default => $reference,
        };
    }

    /**
     * Alternate the case of each character: even positions upper, odd lower.
     * Digits are unaffected, letters flip per position, producing a mixed-case
     * reference distinct from both the all-upper and all-lower forms.
     */
    private function alternatingCase(string $reference): string
    {
        $out = '';
        foreach (str_split($reference) as $i => $char) {
            $out .= $i % 2 === 0 ? strtoupper($char) : strtolower($char);
        }

        return $out;
    }
}
