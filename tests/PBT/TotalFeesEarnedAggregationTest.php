<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Property-based test for the super-admin total-fees-earned aggregation
 * (design Property 26).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. It drives the real HTTP
 * super-admin transactions view (acting as a Super_Admin) against the MySQL
 * test database and reads the `totalApplicationFeesMinor` view data so the
 * reported total reflects production behaviour exactly.
 *
 * Each iteration builds a random number of Companies, each with a random number
 * of Orders in random statuses and random `application_fee_minor` values, then
 * asserts that the reported Platform-wide total equals the independently
 * computed sum of `application_fee_minor` over the fee-earning (paid /
 * free_confirmed) Orders across every Company — cross-Company, not
 * tenant-scoped — and that Orders in excluded statuses (reserved / expired /
 * cancelled / refunded / disputed / voided) contribute nothing.
 */
class TotalFeesEarnedAggregationTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * Order statuses that earn no realised Application_Fee and must therefore
     * be excluded from the total.
     *
     * @var list<string>
     */
    private const NON_EARNING_STATUSES = [
        Order::STATUS_RESERVED,
        Order::STATUS_EXPIRED,
        Order::STATUS_CANCELLED,
        Order::STATUS_REFUNDED,
        Order::STATUS_DISPUTED,
        Order::STATUS_VOIDED,
    ];

    /**
     * The full pool of statuses an iteration may assign to an Order — the two
     * fee-earning states plus every excluded state.
     *
     * @var list<string>
     */
    private const ALL_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
        Order::STATUS_RESERVED,
        Order::STATUS_EXPIRED,
        Order::STATUS_CANCELLED,
        Order::STATUS_REFUNDED,
        Order::STATUS_DISPUTED,
        Order::STATUS_VOIDED,
    ];

    /**
     * Property 26: Total fees earned aggregation — the total Application_Fees
     * reported equals the sum of recorded Application_Fee values of the
     * fee-earning (paid / free_confirmed) Orders across all Companies, and
     * excluded statuses contribute nothing.
     *
     * **Validates: Requirements 20.2**
     */
    // Feature: event-ticketing-platform, Property 26: Total fees earned aggregation — total Application_Fees reported equals the sum of recorded Application_Fee values of paid Orders across all Companies
    public function test_reported_total_equals_sum_of_fee_earning_application_fees_across_all_companies(): void
    {
        $this->forAll(
            // Number of Companies on the Platform this iteration (1–4).
            Generator\choose(1, 4),
            // A pool of per-Company Order counts; the body slices this to the
            // chosen Company count. Counts are small (1–5) to keep each
            // iteration fast while still exercising cross-Company aggregation.
            Generator\tuple(
                Generator\choose(1, 5),
                Generator\choose(1, 5),
                Generator\choose(1, 5),
                Generator\choose(1, 5),
            ),
            // A seed that decides, per Order, its status and application fee.
            Generator\seq(Generator\tuple(
                // Index into ALL_STATUSES (paid / free_confirmed / excluded).
                Generator\choose(0, count(self::ALL_STATUSES) - 1),
                // The recorded application_fee_minor (integer minor units).
                Generator\choose(0, 100_000),
            )),
        )
            ->then(function (int $companyCount, array $countPool, array $orderSeeds): void {
                // Fresh slate each iteration: remove any Orders/Companies from a
                // prior iteration via scoped Eloquent deletes (no DDL/TRUNCATE).
                Order::withoutGlobalScopes()->delete();
                Company::query()->delete();

                $perCompanyCounts = array_slice(array_values($countPool), 0, $companyCount);

                // Ensure at least one Order overall; otherwise the aggregate is a
                // trivial zero and the property is vacuous. If the seed pool is
                // empty, fall back to a single deterministic paid Order seed.
                if ($orderSeeds === []) {
                    $orderSeeds = [[0, 12_345]];
                }

                $expectedTotal = 0;
                $seedCursor = 0;
                $seedCount = count($orderSeeds);

                foreach ($perCompanyCounts as $orderCount) {
                    $company = Company::factory()->create();
                    $event = Event::factory()->for($company)->create();

                    for ($i = 0; $i < $orderCount; $i++) {
                        // Cycle through the generated seeds so every Order gets a
                        // random-but-reproducible status + application fee.
                        [$statusIndex, $feeMinor] = $orderSeeds[$seedCursor % $seedCount];
                        $seedCursor++;

                        $status = self::ALL_STATUSES[$statusIndex];
                        $feeMinor = (int) $feeMinor;

                        Order::factory()->forEvent($event)->create([
                            'order_reference' => strtoupper(Str::random(12)),
                            'status' => $status,
                            'application_fee_minor' => $feeMinor,
                            // Keep totals self-consistent; irrelevant to the fee sum.
                            'ticket_subtotal_minor' => $feeMinor,
                            'order_total_minor' => $feeMinor,
                        ]);

                        // Only paid / free_confirmed Orders contribute.
                        if (in_array($status, [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED], true)) {
                            $expectedTotal += $feeMinor;
                        }
                    }
                }

                // Independently recompute the expected sum straight from the
                // database (cross-Company, not tenant-scoped) as a cross-check
                // that no excluded status leaked in.
                $dbExpectedTotal = (int) Order::withoutGlobalScopes()
                    ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
                    ->sum('application_fee_minor');

                $this->assertSame(
                    $expectedTotal,
                    $dbExpectedTotal,
                    'The in-memory expectation must match the cross-Company DB sum of fee-earning Orders.'
                );

                // Excluded statuses must contribute nothing: their fees are not
                // part of the fee-earning sum.
                $excludedFeesTotal = (int) Order::withoutGlobalScopes()
                    ->whereIn('status', self::NON_EARNING_STATUSES)
                    ->sum('application_fee_minor');
                $allFeesTotal = (int) Order::withoutGlobalScopes()->sum('application_fee_minor');
                $this->assertSame(
                    $allFeesTotal - $excludedFeesTotal,
                    $expectedTotal,
                    'Excluded (non fee-earning) statuses must contribute nothing to the total.'
                );

                // Drive the real super-admin transactions view as a Super_Admin
                // and read the reported total from the view data.
                $superAdmin = User::factory()->superAdmin()->create();

                $response = $this->actingAs($superAdmin)->get('/admin/transactions');
                $response->assertOk();

                $reportedTotal = $response->viewData('totalApplicationFeesMinor');

                // The reported Platform-wide total equals the sum of recorded
                // application_fee_minor over the fee-earning Orders across every
                // Company. (Requirement 20.2, Property 26)
                $this->assertSame(
                    $expectedTotal,
                    (int) $reportedTotal,
                    'Reported total Application_Fees must equal the cross-Company sum over paid/free_confirmed Orders.'
                );
            });
    }
}
