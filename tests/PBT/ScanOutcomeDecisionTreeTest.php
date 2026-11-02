<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\Operator;
use App\Services\QrService;
use App\Services\ScanResult;
use App\Services\TenantContext;
use App\Services\TicketCheckInService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Feature: ios-scanner-app
 *
 * Task 4.3 — Property 1: QR payload resolves to the correct Scan_Outcome.
 *
 * **Validates: Requirements 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 6.2**
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database.
 *
 * Exercises the shared decision tree directly through
 * {@see TicketCheckInService::resolve()} (the service extracted in task 4.1),
 * which both the web scanner and the JSON API funnel through — so the two
 * surfaces can never diverge on what each Scan_Outcome means. The operator is
 * passed in as an {@see Operator} rather than read from `Auth`, exactly as the
 * API caller will supply it.
 *
 * The service runs entirely under the active TenantContext; here it is bound to
 * a freshly created Company so the tenant-scoped Order load (the Requirement 5.5
 * isolation boundary) resolves against a known tenant. `TenantContext` is a
 * request-lifetime singleton, so binding once and clearing between iterations
 * keeps each case's pre-state exact.
 *
 * The property: for each of the six Scan_Outcomes, an input constructed to
 * deterministically produce that branch resolves to exactly that
 * `ScanResult::status`. One case per outcome:
 *
 *   - unreadable       (5.3) — nothing decodable (empty/whitespace, or a
 *                              `.`-bearing payload that is not `reference.token`)
 *   - invalid          (5.4) — a well-shaped `reference.token` payload whose
 *                              token is not the real HMAC (tampered/foreign)
 *   - rejected         (5.5) — a validly signed payload for a reference with no
 *                              matching Order in the active tenant
 *   - failed           (5.6) — a resolved Order that is not confirmed
 *   - already_scanned  (5.7) — a confirmed Order already carrying `scanned_at`
 *   - checked_in       (5.8/6.2) — a confirmed, unscanned Order whose valid QR
 *                              payload (built by {@see QrService}) checks in
 *
 * The generated values drive the shape of each case (reference strings, the
 * non-confirmed status chosen, a prior scanned_at offset) so the property holds
 * across a wide input space, not a single fixed example.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. It was verified with
 * `php -l` and by review.
 */
class ScanOutcomeDecisionTreeTest extends PbtTestCase
{
    use RefreshDatabase;

    private QrService $qr;

    private TicketCheckInService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = app(QrService::class);
        $this->service = app(TicketCheckInService::class);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 1: a payload/reference constructed for each branch of the
     * decision tree resolves to exactly that Scan_Outcome.
     *
     * **Validates: Requirements 5.3, 5.4, 5.5, 5.6, 5.7, 5.8, 6.2**
     */
    // Feature: ios-scanner-app, Property 1: QR payload resolves to the correct Scan_Outcome — one case per outcome (unreadable, invalid, rejected, failed, already_scanned, checked_in)
    public function test_qr_payload_resolves_to_the_correct_scan_outcome(): void
    {
        $this->forAll(
            // An unreadable input: either genuinely empty (whitespace trimmed to
            // nothing) or a `.`-bearing payload with an empty side that cannot
            // decode into (reference, token).
            Generator\elements(['', '   ', '.', '.token', 'ABC123.', "\t\n"]),
            // A base Order_Reference seed used to build the signed/unsigned
            // reference payloads. Kept to the generated form Orders use
            // (uppercase [A-Z0-9]) via normalisation below.
            Generator\choose(1, 1_000_000),
            // Which non-confirmed status drives the `failed` case — every Order
            // state that is not paid/free_confirmed must fail at scan (5.6).
            Generator\elements([
                Order::STATUS_RESERVED,
                Order::STATUS_EXPIRED,
                Order::STATUS_CANCELLED,
                Order::STATUS_REFUNDED,
                Order::STATUS_DISPUTED,
                Order::STATUS_VOIDED,
            ]),
            // How many hours ago the already_scanned Order was first checked in,
            // so the prior scanned_at it reports varies across iterations (5.7).
            Generator\choose(1, 240),
        )
            ->then(function (
                string $unreadableInput,
                int $referenceSeed,
                string $unconfirmedStatus,
                int $scannedHoursAgo,
            ): void {
                // Fresh pre-state each iteration (iterations share the
                // RefreshDatabase transaction): clear any resolved tenant and
                // every Company-owned fixture, scope-free, so the tenant bound
                // below is the only Company in play.
                $tenant = app(TenantContext::class);
                $tenant->clear();
                Order::withoutGlobalScopes()->delete();

                $company = Company::factory()->create();

                // Bind the active tenant so the service's tenant-scoped Order
                // load resolves against this Company (Requirement 5.5).
                $tenant->setCompany($company);

                // The operator identity the service records on the Scan_Event
                // and reads for already_scanned attribution. A real Company
                // scanner, so scanned_by is a valid foreign key.
                $user = User::factory()->for($company)->scanner()->create();
                $operator = Operator::fromUser($user);

                // A distinct Order_Reference per iteration, in the stored
                // uppercase [A-Z0-9] form Orders use. The `.` separator never
                // appears in a reference, so these are unambiguous.
                $knownReference = 'REF'.str_pad((string) $referenceSeed, 9, '0', STR_PAD_LEFT);
                $unknownReference = strtoupper(Str::random(12));

                // ---- unreadable (5.3) -----------------------------------
                // Nothing decodable into a usable (reference, token) or a
                // non-empty typed reference.
                $this->assertSame(
                    ScanResult::STATUS_UNREADABLE,
                    $this->service->resolve($unreadableInput, $operator)->status,
                    'An undecodable/empty input must resolve to unreadable.',
                );

                // ---- invalid (5.4) --------------------------------------
                // A well-shaped `reference.token` payload whose token is not the
                // real HMAC — a tampered or foreign token fails verification.
                $tamperedPayload = $knownReference.QrService::SEPARATOR.str_repeat('0', 64);
                $this->assertSame(
                    ScanResult::STATUS_INVALID,
                    $this->service->resolve($tamperedPayload, $operator)->status,
                    'A payload with a non-matching HMAC token must resolve to invalid.',
                );

                // ---- rejected (5.5) -------------------------------------
                // A genuinely, correctly signed payload for a reference that
                // matches no Order in the active tenant. The HMAC verifies, so
                // this is past the invalid branch; the tenant-scoped load finds
                // nothing and rejects.
                $signedUnknownPayload = $this->qr->payload($unknownReference);
                $this->assertSame(
                    ScanResult::STATUS_REJECTED,
                    $this->service->resolve($signedUnknownPayload, $operator)->status,
                    'A validly signed payload with no matching tenant Order must resolve to rejected.',
                );

                // ---- failed (5.6) ---------------------------------------
                // A resolved Order that is not confirmed (any non
                // paid/free_confirmed state) fails at scan.
                $unconfirmedOrder = Order::factory()->create([
                    'company_id' => $company->id,
                    'event_id' => Event::factory()->for($company),
                    'status' => $unconfirmedStatus,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);
                $this->assertSame(
                    ScanResult::STATUS_FAILED,
                    $this->service->resolve($this->qr->payloadFor($unconfirmedOrder), $operator)->status,
                    "A non-confirmed Order ({$unconfirmedStatus}) must resolve to failed.",
                );

                // ---- already_scanned (5.7) ------------------------------
                // A confirmed Order already carrying scanned_at reports the
                // prior check-in rather than scanning again.
                $alreadyScannedOrder = Order::factory()->create([
                    'company_id' => $company->id,
                    'event_id' => Event::factory()->for($company),
                    'status' => Order::STATUS_PAID,
                    'scanned_at' => now()->subHours($scannedHoursAgo),
                    'scanned_by' => $user->id,
                ]);
                $this->assertSame(
                    ScanResult::STATUS_ALREADY_SCANNED,
                    $this->service->resolve($this->qr->payloadFor($alreadyScannedOrder), $operator)->status,
                    'A confirmed Order already scanned must resolve to already_scanned.',
                );

                // ---- checked_in (5.8 / 6.2) -----------------------------
                // A confirmed, previously-unscanned Order whose valid QR payload
                // (built by QrService) checks in on first scan.
                $freshOrder = Order::factory()->create([
                    'company_id' => $company->id,
                    'event_id' => Event::factory()->for($company),
                    'status' => Order::STATUS_PAID,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);
                $this->assertSame(
                    ScanResult::STATUS_CHECKED_IN,
                    $this->service->resolve($this->qr->payloadFor($freshOrder), $operator)->status,
                    'A confirmed, unscanned Order must resolve to checked_in.',
                );
            });
    }
}
