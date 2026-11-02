<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\ScanEvent;
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
 * Feature: ios-scanner-app, Property 4: Every resolved check-in records exactly
 * one Scan_Event.
 *
 * Property-based test for ScanEvent recording (design Property 4). Uses the
 * Eris library (never hand-rolled generators) with a minimum of 100 iterations,
 * per the design's Testing Strategy, and runs against the real MySQL test
 * database so the tenant-scoped `scan_events` insert the service performs in
 * {@see TicketCheckInService::logScanEvent()} behaves exactly as in production.
 *
 * The shared {@see TicketCheckInService::resolve()} records a Scan_Event for
 * EVERY outcome — a clean check-in AND every problem (duplicate, rejected,
 * failed, unreadable, invalid) — under the Active_Company, mapping the
 * ScanResult status to a ScanEvent outcome (Requirement 5.10):
 *
 *     checked_in      -> OUTCOME_SUCCESS
 *     already_scanned -> OUTCOME_DUPLICATE
 *     rejected        -> OUTCOME_REJECTED
 *     failed          -> OUTCOME_FAILED
 *     unreadable      -> OUTCOME_UNREADABLE
 *     invalid         -> OUTCOME_INVALID
 *
 * How each outcome is driven to resolve() once:
 *   - checked_in:      a confirmed, unscanned Order's QR payload.
 *   - already_scanned: a confirmed Order already carrying `scanned_at`.
 *   - rejected:        a well-formed reference matching no Order in the tenant.
 *   - failed:          a voided (non-confirmed) Order's QR payload.
 *   - unreadable:      an empty / separator-only payload that decodes to nothing.
 *   - invalid:         a decodable `reference.token` payload whose token is a
 *                      tampered HMAC, so verification fails.
 *
 * The invariant checked after EACH single resolve() (Requirement 5.10):
 *   - EXACTLY ONE new `scan_events` row exists for the Active_Company — the
 *     count delta across the single resolve is exactly 1.
 *   - that row carries the expected `outcome` for the generated scenario, the
 *     operator's `operator_id`/`operator_name`, and — when an Order was
 *     resolved — that Order's `reference` (null for unreadable/invalid where no
 *     Order is in hand).
 */
class ScanEventRecordedPerResolveTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 4: Every resolved check-in records exactly one Scan_Event — for
     * every outcome, a single resolve() adds exactly one `scan_events` row under
     * the Active_Company with the correct outcome mapping, operator identity and
     * reference.
     *
     * **Validates: Requirements 5.10**
     */
    // Feature: ios-scanner-app, Property 4: Every resolved check-in records exactly one Scan_Event per resolved request under the Active_Company with the correct outcome mapping
    public function test_each_resolve_records_exactly_one_scan_event(): void
    {
        $qr = app(QrService::class);
        $service = app(TicketCheckInService::class);

        $this->forAll(
            // One case per outcome of the decision tree, so the whole status ->
            // outcome mapping is exercised across iterations.
            Generator\elements(
                ScanResult::STATUS_CHECKED_IN,
                ScanResult::STATUS_ALREADY_SCANNED,
                ScanResult::STATUS_REJECTED,
                ScanResult::STATUS_FAILED,
                ScanResult::STATUS_UNREADABLE,
                ScanResult::STATUS_INVALID,
            ),
        )
            ->then(function (string $scenario) use ($qr, $service): void {
                // Each iteration builds its OWN Company/Event + scanner and
                // reasons only about those rows (RefreshDatabase wraps the test
                // in a transaction; DDL / TRUNCATE is banned). The scenario is
                // resolved exactly once, then the new scan_events rows for this
                // Company are asserted.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->create();

                $scanner = User::factory()->scanner()->for($company)->create();
                $operator = Operator::fromUser($scanner);

                // The service reads the Order and records the ScanEvent through
                // the tenant scope, so bind the Company on the shared context.
                app(TenantContext::class)->setCompany($company);

                // Build the input payload and the expected outcome/reference for
                // the generated scenario. `$expectedReference` is the Order's
                // reference where one is resolved, or null where none is.
                [$payload, $expectedOutcome, $expectedReference] =
                    $this->scenario($scenario, $qr, $event);

                // The count of this Company's scan_events immediately before the
                // single resolve, so we can assert the delta is exactly 1.
                $before = ScanEvent::query()->count();

                $result = $service->resolve($payload, $operator);

                // The resolved status must match the scenario we built — a guard
                // that the input really drove the intended branch of the tree.
                $this->assertSame(
                    $scenario,
                    $result->status,
                    "The {$scenario} input must resolve to that status.",
                );

                // EXACTLY ONE new scan_events row for the Active_Company. The
                // count is already tenant-scoped (BelongsToCompany global scope),
                // so this counts only this Company's rows. (Requirement 5.10)
                $after = ScanEvent::query()->count();

                $this->assertSame(
                    1,
                    $after - $before,
                    "A single resolve must record exactly one scan_events row under the Active_Company (scenario: {$scenario}).",
                );

                // The one recorded row carries the correct outcome mapping,
                // operator identity, and reference (where an Order was resolved).
                $row = ScanEvent::query()->latest('id')->firstOrFail();

                $this->assertSame($company->getKey(), (int) $row->company_id, 'The ScanEvent belongs to the Active_Company.');
                $this->assertSame($expectedOutcome, $row->outcome, "The outcome must map from the {$scenario} status.");
                $this->assertSame($scanner->getKey(), (int) $row->operator_id, 'The ScanEvent records the operator id.');
                $this->assertSame($scanner->name, $row->operator_name, 'The ScanEvent records the operator name.');
                $this->assertSame($expectedReference, $row->reference, 'The ScanEvent records the resolved Order reference (null when none).');

                // Per-iteration cleanup keeps iterations independent without any
                // DDL / TRUNCATE — plain scoped deletes only.
                app(TenantContext::class)->clear();
                ScanEvent::withoutGlobalScopes()->where('company_id', $company->getKey())->delete();
            });
    }

    /**
     * Build the resolve() input for a scenario and return the tuple
     * `[payload, expectedScanEventOutcome, expectedReferenceOrNull]`.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function scenario(string $scenario, QrService $qr, Event $event): array
    {
        return match ($scenario) {
            // 5.8 -> success: a confirmed, unscanned Order's QR payload.
            ScanResult::STATUS_CHECKED_IN => (function () use ($qr, $event): array {
                $order = Order::factory()->forEvent($event)->create([
                    'status' => Order::STATUS_PAID,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);

                // One Ticket so the success breakdown is non-trivial; the
                // ScanEvent still records exactly one row regardless.
                $type = TicketType::factory()->forEvent($event)->create(['name' => 'General']);
                Ticket::factory()->forOrder($order)->forTicketType($type)->create();

                return [$qr->payload($order->order_reference), ScanEvent::OUTCOME_SUCCESS, $order->order_reference];
            })(),

            // 5.7 -> duplicate: a confirmed Order already checked in.
            ScanResult::STATUS_ALREADY_SCANNED => (function () use ($qr, $event): array {
                $order = Order::factory()->forEvent($event)->create([
                    'status' => Order::STATUS_PAID,
                    'scanned_at' => now()->subHour(),
                    'scanned_by' => null,
                ]);

                return [$qr->payload($order->order_reference), ScanEvent::OUTCOME_DUPLICATE, $order->order_reference];
            })(),

            // 5.5 -> rejected: a well-formed reference matching no Order in the
            // tenant scope. No Order is resolved, so no reference is recorded.
            ScanResult::STATUS_REJECTED => [
                $qr->payload('ZZ00NOORDER0'),
                ScanEvent::OUTCOME_REJECTED,
                null,
            ],

            // 5.6 -> failed: a voided (non-confirmed) Order's QR payload.
            ScanResult::STATUS_FAILED => (function () use ($qr, $event): array {
                $order = Order::factory()->forEvent($event)->create([
                    'status' => Order::STATUS_VOIDED,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);

                return [$qr->payload($order->order_reference), ScanEvent::OUTCOME_FAILED, $order->order_reference];
            })(),

            // 5.3 -> unreadable: a separator-only payload decodes to nothing.
            ScanResult::STATUS_UNREADABLE => [
                QrService::SEPARATOR,
                ScanEvent::OUTCOME_UNREADABLE,
                null,
            ],

            // 5.4 -> invalid: a decodable reference.token payload whose token is
            // a tampered HMAC, so verification fails. No Order is resolved.
            ScanResult::STATUS_INVALID => [
                'ABCDEF123456'.QrService::SEPARATOR.str_repeat('0', 64),
                ScanEvent::OUTCOME_INVALID,
                null,
            ],
        };
    }
}
