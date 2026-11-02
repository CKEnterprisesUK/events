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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Feature: ios-scanner-app
 *
 * Task 4.4 — Property 2: Check-in is atomic under concurrency.
 *
 * **Validates: Requirements 5.8, 5.9**
 *
 * Where {@see SingleAtomicCheckInTest} races the raw guarded
 * `UPDATE ... WHERE scanned_at IS NULL` at the database layer, THIS property
 * drives the full {@see TicketCheckInService} (task 4.1) — the single shared
 * decision tree the web scanner and the iOS/JSON API both call. It asserts the
 * service's *outcome contract*: across many confirmed, unscanned Orders each
 * hit by several concurrent attempts, exactly one attempt returns a
 * `checked_in` {@see ScanResult} and every other returns `already_scanned`
 * carrying the winner's `scanned_at`.
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy.
 *
 * How concurrency is modelled without threads:
 *   True OS-thread parallelism is not available inside a single PHP test. The
 *   serialization guarantee lives in MySQL: the service's
 *   `UPDATE orders SET scanned_at, scanned_by WHERE id = ? AND scanned_at IS
 *   NULL` lets exactly one racing writer set the row while `scanned_at` is
 *   still NULL; every later writer matches zero rows, hits the service's
 *   `$affected !== 1` branch, refreshes, and returns `already_scanned` with the
 *   winner's instant. Concurrency is therefore represented as a randomly-sized,
 *   interleaved SEQUENCE of {@see TicketCheckInService::resolve()} calls against
 *   the SAME confirmed, unscanned Order, each call made by one of several
 *   operators in the Order's Company. Interleaving a sequence enumerates every
 *   ordering the guarded UPDATE admits — and the only orderings reachable in
 *   production are serializations of concurrent scans, which is exactly what an
 *   interleaved sequence enumerates. The payload alternates between the Order's
 *   QR_Payload and its manually-typed Order_Reference so both entry paths race
 *   through the identical atomic tail (5.8).
 *
 * The invariants checked after the whole interleaving (Requirements 5.8, 5.9):
 *   - EXACTLY ONE attempt returns a `checked_in` ScanResult; all others return
 *     `already_scanned`.
 *   - Every `already_scanned` result carries the winner's `scanned_at`,
 *     byte-for-byte unchanged — never NULL, never a different instant.
 *   - The winning `checked_in` result's `scanned_at` matches the Order's
 *     persisted `scanned_at`, which never changes thereafter.
 *
 * NOTE: This test was authored but NOT executed — MySQL is unreachable in this
 * environment so the PHPUnit suite cannot be run here. Concurrency semantics
 * genuinely require the real MySQL atomic UPDATE, so it MUST be run against the
 * real MySQL test database (per {@see PbtTestCase}); it was verified here with
 * `php -l` and by review.
 */
class AtomicConcurrencyCheckInTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Auth::logout();

        parent::tearDown();
    }

    /**
     * Property 2: Check-in is atomic under concurrency — for any confirmed,
     * unscanned Order, across any interleaving of concurrent check-in attempts
     * through the shared service, exactly one returns `checked_in` and every
     * other returns `already_scanned` carrying the winner's `scanned_at`.
     *
     * **Validates: Requirements 5.8, 5.9**
     */
    // Feature: ios-scanner-app, Property 2: Check-in is atomic under concurrency — drive many confirmed/unscanned Orders with concurrent attempts; assert exactly one checked_in and the rest already_scanned carrying the winner's scanned_at
    public function test_concurrent_attempts_yield_exactly_one_checked_in_rest_already_scanned(): void
    {
        $qr = app(QrService::class);

        $this->forAll(
            // Number of operators in the Order's Company (1–3): the same Order
            // may be scanned by several devices/staff at once.
            Generator\choose(1, 3),
            // The interleaved attempt sequence. Each entry is [operatorIndex,
            // useQrPayload]: which operator makes the attempt and whether they
            // submit the QR payload (true) or type the Order_Reference (false).
            // A length >= 1 is guaranteed by prepending one attempt in the body.
            Generator\seq(Generator\tuple(
                Generator\choose(0, 2),
                Generator\bool(),
            )),
            // Which confirmed status the Order carries: both paid and
            // free-confirmed Orders are valid, unscanned, scannable Orders.
            Generator\elements(Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED),
        )
            ->then(function (int $operatorCount, array $attempts, string $confirmedStatus) use ($qr): void {
                // Each iteration builds its OWN Company/Event/Order + operators
                // and reasons only about those rows (RefreshDatabase wraps the
                // test in a transaction; DDL / TRUNCATE is banned). A scope-free
                // delete across all orders would needlessly contend with the
                // guarded UPDATE this property races.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->create();

                // Several operators in the SAME Company — check-in is scoped to
                // the operator's Company, so every operator here can see and
                // check in this Order.
                $operators = [];
                for ($i = 0; $i < $operatorCount; $i++) {
                    $operators[] = User::factory()->scanner()->for($company)->create();
                }

                // A confirmed, unscanned Order carrying exactly one QR payload
                // derived from its Order_Reference.
                $order = Order::factory()->forEvent($event)->create([
                    'status' => $confirmedStatus,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);

                // The service reads the Order through the tenant scope, so bind
                // the Company on the shared request-scoped context exactly as
                // the web/API middleware would.
                app(TenantContext::class)->setCompany($company);

                // The single QR payload for this Order, and its manual
                // Order_Reference. Both funnel through the identical atomic tail.
                $payload = $qr->payload($order->order_reference);
                $reference = $order->order_reference;

                // Guarantee at least one attempt: prepend one so an empty
                // generated sequence still scans once. Use the QR payload for
                // the guaranteed first attempt.
                array_unshift($attempts, [0, true]);

                $checkedInCount = 0;
                $winnerScannedAt = null;
                $winnerOperatorId = null;

                foreach ($attempts as [$operatorIndexRaw, $useQrPayload]) {
                    $operator = $operators[$operatorIndexRaw % $operatorCount];

                    // A FRESH service per attempt so no in-memory state leaks
                    // between attempts — each attempt is an independent caller,
                    // as two racing requests would be in production.
                    $service = new TicketCheckInService($qr);

                    $result = $service->resolve(
                        $useQrPayload ? $payload : $reference,
                        new Operator($operator->getKey(), $operator->name),
                    );

                    if ($result->status === ScanResult::STATUS_CHECKED_IN) {
                        $checkedInCount++;

                        // Record the winner the first (and only) time a
                        // check-in is returned.
                        $this->assertNull(
                            $winnerScannedAt,
                            'At most one attempt may return checked_in.'
                        );
                        $this->assertInstanceOf(
                            Carbon::class,
                            $result->scannedAt,
                            'A checked_in result must carry its scanned_at.'
                        );

                        $winnerScannedAt = $result->scannedAt;
                        $winnerOperatorId = $operator->getKey();

                        // The breakdown is present on a successful check-in.
                        $this->assertNotEmpty(
                            $result->breakdown,
                            'A checked_in result carries the Order breakdown.'
                        );
                    } else {
                        // Every non-winning attempt must be already_scanned —
                        // never rejected/failed/invalid for a confirmed,
                        // reference-valid Order. (Requirement 5.9)
                        $this->assertSame(
                            ScanResult::STATUS_ALREADY_SCANNED,
                            $result->status,
                            'A losing concurrent attempt must report already_scanned.'
                        );

                        // A losing attempt can only occur AFTER the winner has
                        // recorded the check-in, and it must carry the winner's
                        // instant unchanged. (Requirement 5.8)
                        $this->assertNotNull(
                            $winnerScannedAt,
                            'An already_scanned outcome implies a prior check-in winner.'
                        );
                        $this->assertInstanceOf(
                            Carbon::class,
                            $result->scannedAt,
                            'An already_scanned result must carry the winner\'s scanned_at.'
                        );
                        $this->assertTrue(
                            $result->scannedAt->equalTo($winnerScannedAt),
                            'An already_scanned result must carry the winner\'s scanned_at, unchanged.'
                        );
                    }
                }

                // EXACTLY ONE attempt recorded the check-in, no matter how many
                // attempts (repeated or concurrent) were made. (Requirement 5.8)
                $this->assertSame(
                    1,
                    $checkedInCount,
                    'Exactly one attempt among all concurrent attempts returns checked_in.'
                );

                // The persisted Order reflects the winner: scanned_at matches the
                // winning result's instant and never changes; scanned_by is the
                // winning operator. (Requirements 5.8, 5.9)
                $final = Order::withoutGlobalScopes()->findOrFail($order->getKey());

                $this->assertNotNull(
                    $final->scanned_at,
                    'The winning check-in must have set scanned_at on the Order.'
                );
                $this->assertTrue(
                    $final->scanned_at->equalTo($winnerScannedAt),
                    'The Order\'s persisted scanned_at must equal the winner\'s result instant.'
                );
                $this->assertSame(
                    $winnerOperatorId,
                    (int) $final->scanned_by,
                    'scanned_by must be the first (winning) operator and never change.'
                );

                // A further attempt after the Order is already checked in still
                // yields already_scanned with the original scanned_at — the
                // check-in stays single no matter how many more attempts arrive.
                $afterService = new TicketCheckInService($qr);
                $afterOperator = $operators[0];
                $afterResult = $afterService->resolve(
                    $payload,
                    new Operator($afterOperator->getKey(), $afterOperator->name),
                );

                $this->assertSame(
                    ScanResult::STATUS_ALREADY_SCANNED,
                    $afterResult->status,
                    'A later attempt on an already-checked-in Order must report already_scanned.'
                );
                $this->assertTrue(
                    $afterResult->scannedAt instanceof Carbon
                        && $afterResult->scannedAt->equalTo($winnerScannedAt),
                    'A later attempt must report the original scanned_at, unchanged.'
                );

                // Per-iteration cleanup keeps iterations independent without any
                // DDL / TRUNCATE — plain scoped deletes only.
                app(TenantContext::class)->clear();
                Order::withoutGlobalScopes()->whereKey($order->getKey())->delete();
                foreach ($operators as $operatorToDelete) {
                    User::withoutGlobalScopes()->whereKey($operatorToDelete->getKey())->delete();
                }
            });
    }
}
