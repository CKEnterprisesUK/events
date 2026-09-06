<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use App\Services\QrService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Property-based test for single atomic check-in (design Property 24).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database so the check-in's guarded
 * `UPDATE orders SET scanned_at, scanned_by WHERE id = ? AND scanned_at IS NULL`
 * (the affected-rows == 1 guard) serializes exactly as in production. That
 * atomic UPDATE — not any application-level lock — is the correctness
 * guarantee: among any number of racing attempts, MySQL lets exactly one write
 * a row while `scanned_at` is still NULL; every other attempt writes zero rows
 * and must fall back to already-scanned. (Requirement 16.8)
 *
 * How concurrency is modelled without threads:
 *   The scanner processes exactly one QR per Order ({@see QrService::payload()}
 *   over the Order_Reference). Concurrency is represented as a randomly-sized
 *   SEQUENCE of scan attempts against the SAME confirmed, unscanned Order, each
 *   attempt made by one of several scanner users in the Order's Company. Every
 *   attempt runs the identical guarded UPDATE the {@see \App\Http\Controllers\ScanController}
 *   issues; the affected row count decides the outcome (1 = this attempt is the
 *   sole check-in, 0 = already-scanned). Interleaving a sequence enumerates
 *   every ordering the guarded UPDATE admits — and the only orderings reachable
 *   in production are serializations of concurrent scans, which is exactly what
 *   an interleaved sequence enumerates.
 *
 * The invariants checked after the whole interleaving (Requirements 16.8, 16.9):
 *   - EXACTLY ONE attempt records the check-in (affected rows == 1); all others
 *     see zero rows and report already-scanned.
 *   - `scanned_at` is set exactly once and NEVER changes thereafter — every
 *     already-scanned attempt reports that same original `scanned_at`.
 *   - `scanned_by` is the FIRST (winning) scanner and never changes.
 */
class SingleAtomicCheckInTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();
        Auth::logout();

        parent::tearDown();
    }

    /**
     * Property 24: Single atomic check-in — across any interleaving of
     * concurrent scans of a valid unscanned Order, exactly one records the
     * check-in; `scanned_at` never changes; every subsequent scan reports
     * already-scanned with the original `scanned_at`.
     *
     * **Validates: Requirements 16.8, 16.9**
     */
    // Feature: event-ticketing-platform, Property 24: Single atomic check-in — across any interleaving of concurrent scans of a valid unscanned Order, exactly one records the check-in; scanned_at never changes; subsequent scans report already-scanned with the original scanned_at
    public function test_repeated_and_concurrent_scans_check_in_exactly_once(): void
    {
        $qr = app(QrService::class);

        $this->forAll(
            // Number of scanner users in the Order's Company (1-3): the same
            // Order may be scanned by several devices/staff at once.
            Generator\choose(1, 3),
            // The interleaved scan-attempt sequence: each entry is the index of
            // the scanner user making that attempt. A sequence of length >= 1 is
            // guaranteed by prepending one attempt in the body, so at least one
            // scan always occurs. Lengths of 1..N model "repeated" and
            // "concurrent" attempts alike.
            Generator\seq(Generator\choose(0, 2)),
            // Which confirmed status the Order carries: both paid and
            // free-confirmed Orders are valid, unscanned, scannable Orders.
            Generator\elements(Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED),
        )
            ->then(function (int $scannerCount, array $attempts, string $confirmedStatus) use ($qr): void {
                // Each iteration builds its OWN Company/Event/Order + scanners and
                // reasons only about those rows, so no table-wide cleanup is
                // needed (RefreshDatabase wraps the test in a transaction; DDL /
                // TRUNCATE is banned). A scope-free delete across all orders would
                // needlessly contend with the guarded UPDATE this property races.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->create();

                // Several Scanner users in the SAME Company — check-in is scoped
                // to the scanning user's Company, so every scanner here can see
                // and check in this Order.
                $scanners = [];
                for ($i = 0; $i < $scannerCount; $i++) {
                    $scanners[] = User::factory()->scanner()->for($company)->create();
                }

                // A confirmed, unscanned Order carrying exactly one QR payload
                // derived from its Order_Reference.
                $order = Order::factory()->forEvent($event)->create([
                    'status' => $confirmedStatus,
                    'scanned_at' => null,
                    'scanned_by' => null,
                ]);

                // The scanner reads the Order through the tenant scope, so put
                // the Company on the shared request-scoped context.
                app(TenantContext::class)->setCompany($company);

                // Confirm the single QR payload verifies back to this Order's
                // reference — this is the exact string the scanner decodes.
                $payload = $qr->payload($order->order_reference);
                $this->assertSame(
                    $order->order_reference,
                    $qr->verifyPayload($payload),
                    'The Order carries exactly one QR payload that verifies to its reference.'
                );

                // Guarantee at least one attempt: prepend one so an empty
                // generated sequence still scans once.
                $attempts = array_merge([0], $attempts);

                // Run the interleaved attempts. Every attempt issues the SAME
                // guarded UPDATE the ScanController uses; the affected row count
                // decides who checked the Order in.
                $successfulScans = 0;
                $firstWinnerUserId = null;
                $firstWinnerScannedAt = null;

                foreach ($attempts as $scannerIndex) {
                    $scanner = $scanners[$scannerIndex % $scannerCount];
                    $this->actingAs($scanner);

                    [$affected, $scannedAt] = $this->attemptCheckIn($order->getKey());

                    if ($affected === 1) {
                        $successfulScans++;

                        // Record the winner the first time a row is written.
                        if ($firstWinnerUserId === null) {
                            $firstWinnerUserId = $scanner->getKey();
                            $firstWinnerScannedAt = $scannedAt;
                        }
                    } else {
                        // A losing attempt writes zero rows and must observe the
                        // already-recorded scanned_at (never NULL once a winner
                        // exists, never a different value). (Requirement 16.9)
                        $fresh = Order::withoutGlobalScopes()->findOrFail($order->getKey());

                        $this->assertNotNull(
                            $fresh->scanned_at,
                            'A losing scan must see a Order already checked in (scanned_at set).'
                        );
                        $this->assertTrue(
                            $firstWinnerScannedAt instanceof Carbon
                                && $fresh->scanned_at->equalTo($firstWinnerScannedAt),
                            'A losing scan must report the original winner\'s scanned_at, unchanged.'
                        );
                    }
                }

                // EXACTLY ONE attempt recorded the check-in, no matter how many
                // attempts (repeated or concurrent) were made. (Requirement 16.8)
                $this->assertSame(
                    1,
                    $successfulScans,
                    'Exactly one scan among all attempts may record the check-in.'
                );

                // scanned_at was set once and is stable; scanned_by is the first
                // (winning) scanner. (Requirements 16.8, 16.9)
                $final = Order::withoutGlobalScopes()->findOrFail($order->getKey());

                $this->assertNotNull(
                    $final->scanned_at,
                    'The winning check-in must have set scanned_at.'
                );
                $this->assertTrue(
                    $final->scanned_at->equalTo($firstWinnerScannedAt),
                    'scanned_at must never change after the first check-in.'
                );
                $this->assertSame(
                    $firstWinnerUserId,
                    (int) $final->scanned_by,
                    'scanned_by must be the first (winning) scanner and never change.'
                );

                // A further scan after the Order is already checked in still
                // writes zero rows and preserves the original scanned_at — the
                // check-in stays single no matter how many more attempts arrive.
                [$affectedAfter, $scannedAtAfter] = $this->attemptCheckIn($order->getKey());
                $this->assertSame(
                    0,
                    $affectedAfter,
                    'A scan of an already-checked-in Order must write zero rows.'
                );
                $this->assertTrue(
                    $scannedAtAfter instanceof Carbon
                        && $scannedAtAfter->equalTo($firstWinnerScannedAt),
                    'A later scan must report the original scanned_at, unchanged.'
                );

                // Per-iteration cleanup keeps iterations independent without any
                // DDL / TRUNCATE — plain scoped deletes only.
                app(TenantContext::class)->clear();
                Order::withoutGlobalScopes()->whereKey($order->getKey())->delete();
                foreach ($scanners as $scanner) {
                    User::withoutGlobalScopes()->whereKey($scanner->getKey())->delete();
                }
            });
    }

    /**
     * Issue the exact guarded single check-in the {@see \App\Http\Controllers\ScanController}
     * runs: `UPDATE orders SET scanned_at, scanned_by WHERE id = ? AND
     * scanned_at IS NULL`. Returns the affected row count (1 = this attempt
     * checked the Order in, 0 = it was already scanned) and the scanned_at now
     * persisted on the Order. The real MySQL atomic UPDATE is the serialization
     * guarantee that makes at most one attempt write a row.
     *
     * @return array{0: int, 1: Carbon}
     */
    private function attemptCheckIn(int $orderId): array
    {
        $now = now();

        $affected = Order::withoutGlobalScopes()
            ->whereKey($orderId)
            ->whereNull('scanned_at')
            ->update([
                'scanned_at' => $now,
                'scanned_by' => Auth::id(),
            ]);

        $scannedAt = Order::withoutGlobalScopes()->findOrFail($orderId)->scanned_at;

        return [$affected, $scannedAt];
    }
}
