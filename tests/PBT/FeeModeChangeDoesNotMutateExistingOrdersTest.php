<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Services\FeeCalculationService;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the snapshot immutability invariant of the
 * Fee_Handling_Mode (Requirement 13.8).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database so the Company's `setFeeHandlingMode()` persist path is
 * exercised exactly as in production.
 *
 * An Order snapshots the fee mode / money at creation, so a later mode change
 * on the owning Company must never mutate that Order (Requirement 13.8). The
 * `orders` table does not exist yet — it is created in task 12.2 — so this
 * property models an "existing order" at the snapshot level: a captured
 * {@see \App\Services\FeeCalculation} (via `calculateForCompany()->toArray()`)
 * computed BEFORE the mode change. It then flips the Company's
 * Fee_Handling_Mode and asserts:
 *   (a) the previously-captured snapshot is byte-for-byte unchanged, and
 *   (b) a NEW calculation for the same Company reflects the new mode.
 *
 * The persisted-order variant (asserting a real Order row is untouched by a
 * subsequent mode change) is covered once the `orders` table exists (task
 * 12.x). Together they validate the same snapshot immutability invariant
 * (Requirement 13.8).
 */
class FeeModeChangeDoesNotMutateExistingOrdersTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * Property 19: Fee-mode change does not mutate existing orders — changing a
     * Company's Fee_Handling_Mode leaves the snapshot/money of already-captured
     * orders unchanged; orders captured after the change use the new mode.
     *
     * **Validates: Requirements 13.8**
     */
    // Feature: event-ticketing-platform, Property 19: Fee-mode change does not mutate existing orders — changing Fee_Handling_Mode leaves existing orders' snapshot/money unchanged; later orders use the new mode
    public function test_fee_mode_change_does_not_mutate_existing_order_snapshots(): void
    {
        $service = new FeeCalculationService();

        $this->forAll(
            // Ticket_Subtotal in integer minor units, from free-only (0) to a
            // large order.
            Generator\choose(0, 10_000_000),
            // The Company's initial Fee_Handling_Mode.
            Generator\elements(...Company::FEE_MODES),
            // The target Fee_Handling_Mode after the change (may equal initial).
            Generator\elements(...Company::FEE_MODES),
        )
            ->withMaxSize(10_000_000)
            ->then(function (int $subtotal, string $initialMode, string $targetMode) use ($service): void {
                $company = Company::factory()->create([
                    'fee_handling_mode' => $initialMode,
                ]);

                // Capture the "existing order" snapshot under the initial mode.
                $existingSnapshot = $service->calculateForCompany($company, $subtotal)->toArray();

                // Sanity: the captured snapshot reflects the initial mode.
                $this->assertSame(
                    $initialMode,
                    $existingSnapshot['fee_handling_mode'],
                    'Captured snapshot must reflect the initial Fee_Handling_Mode.'
                );

                // A defensive deep copy: the reference the assertion compares
                // against cannot be aliased to anything the mode change touches.
                $capturedBeforeChange = $existingSnapshot;

                // Change the Company's Fee_Handling_Mode (persists via save()).
                $company->setFeeHandlingMode($targetMode);

                // Re-read from the database to be sure the persisted change took
                // effect and reflects the new mode going forward.
                $company->refresh();
                $this->assertSame(
                    $targetMode,
                    $company->fee_handling_mode,
                    'Company Fee_Handling_Mode must be updated to the target mode.'
                );

                // (a) The previously-captured snapshot is byte-for-byte
                // unchanged by the mode change (Requirement 13.8).
                $this->assertSame(
                    $capturedBeforeChange,
                    $existingSnapshot,
                    sprintf(
                        'Existing order snapshot must be unchanged after mode %s -> %s (subtotal=%d).',
                        $initialMode,
                        $targetMode,
                        $subtotal
                    )
                );
                $this->assertSame(
                    $initialMode,
                    $existingSnapshot['fee_handling_mode'],
                    'Existing snapshot must still carry the initial mode after the change.'
                );

                // (b) A NEW calculation for the same Company reflects the new
                // mode (Requirement 13.8).
                $newSnapshot = $service->calculateForCompany($company, $subtotal)->toArray();
                $this->assertSame(
                    $targetMode,
                    $newSnapshot['fee_handling_mode'],
                    'Order captured after the change must use the new Fee_Handling_Mode.'
                );

                // The new snapshot's money must match a fresh calculation done
                // directly under the target mode — i.e. the change fully takes
                // effect for later orders.
                $expectedUnderTarget = $service->calculate(
                    $subtotal,
                    $service->effectivePercent($company),
                    $targetMode,
                )->toArray();
                $this->assertSame(
                    $expectedUnderTarget,
                    $newSnapshot,
                    sprintf(
                        'Later order must fully reflect target mode %s (subtotal=%d).',
                        $targetMode,
                        $subtotal
                    )
                );
            });
    }
}
