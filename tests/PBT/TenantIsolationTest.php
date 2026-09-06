<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for tenant isolation (Property 1).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, and runs against the real
 * MySQL test database.
 *
 * Tenant isolation is a single invariant carried by every Company-owned model
 * through the {@see BelongsToCompany} trait (global {@see \App\Models\Scopes\TenantScope}
 * + company_id auto-fill), reading the active Company from {@see TenantContext}.
 * Because scan lookups (Requirement 16.6) and GDPR operations (Requirement 22.5)
 * are ordinary reads/writes/updates/deletes on those same Company-owned models,
 * exercising the shared trait on a representative Company-owned model
 * establishes the property for all of them. A test-only {@see IsolatedRecord}
 * model backed by a temp table stands in for the concrete models, exactly as
 * the Widget model does in {@see \Tests\Feature\TenantResolutionTest}.
 */
class TenantIsolationTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 1: Tenant isolation — reads/writes/updates/deletes affect only
     * the resolved company_id; cross-Company access is denied and leaves the
     * record byte-for-byte unchanged (including scan lookups and GDPR ops).
     *
     * **Validates: Requirements 1.4, 1.5, 3.8, 3.10, 16.6, 22.5**
     */
    // Feature: event-ticketing-platform, Property 1: Tenant isolation — reads/writes/updates/deletes affect only the resolved company_id; cross-Company access denied and leaves the record byte-for-byte unchanged (incl. scan lookups and GDPR ops)
    public function test_isolation_confines_every_operation_to_the_resolved_company(): void
    {
        $this->forAll(
            // 2–5 Companies so there is always at least one foreign tenant.
            Generator\choose(2, 5),
            // Per-Company record counts and labels, plus the index of the
            // Company that becomes the resolved tenant for this iteration.
            Generator\seq(Generator\choose(0, 4)),
            Generator\nat()
        )
            ->then(function (int $companyCount, array $recordCounts, int $resolvedPick): void {
                app(TenantContext::class)->clear();
                IsolatedRecord::withoutGlobalScopes()->delete();

                // Build the Companies and seed each with its own rows, writing
                // directly (scope-free) so the pre-state is known exactly.
                $companies = [];
                $ownRows = [];   // company_id => list<int> of owned row ids
                $snapshot = [];  // row id => full attribute array (byte-for-byte)

                for ($i = 0; $i < $companyCount; $i++) {
                    $company = Company::factory()->create();
                    $companies[] = $company;
                    $ownRows[$company->id] = [];

                    $count = $recordCounts[$i] ?? 0;
                    for ($j = 0; $j < $count; $j++) {
                        $row = IsolatedRecord::withoutGlobalScopes()->create([
                            'company_id' => $company->id,
                            'label' => "c{$company->id}-r{$j}",
                        ]);
                        $ownRows[$company->id][] = $row->id;
                        $snapshot[$row->id] = $row->fresh()->getAttributes();
                    }
                }

                $resolved = $companies[$resolvedPick % $companyCount];
                app(TenantContext::class)->setCompany($resolved);

                $resolvedIds = $ownRows[$resolved->id];
                $foreignIds = [];
                foreach ($ownRows as $companyId => $ids) {
                    if ($companyId !== $resolved->id) {
                        $foreignIds = array_merge($foreignIds, $ids);
                    }
                }

                // READ: scoped reads return exactly the resolved Company's rows.
                $visibleIds = IsolatedRecord::query()->pluck('id')->sort()->values()->all();
                $expectedIds = collect($resolvedIds)->sort()->values()->all();
                $this->assertSame(
                    $expectedIds,
                    $visibleIds,
                    'Scoped reads must return only the resolved Company rows.'
                );

                // WRITE: a create with no explicit company_id belongs to the
                // resolved Company (Requirement 1.1 / isolation on writes).
                $created = IsolatedRecord::query()->create(['label' => 'written']);
                $this->assertSame(
                    $resolved->id,
                    $created->company_id,
                    'A scoped create must belong to the resolved Company.'
                );

                // UPDATE across the whole table under the scope touches only the
                // resolved Company's rows (plus the row just written).
                $affected = IsolatedRecord::query()->update(['label' => 'touched']);
                $this->assertSame(
                    count($resolvedIds) + 1,
                    $affected,
                    'A scoped bulk update must affect only resolved-Company rows.'
                );

                // Cross-Company access is denied: no foreign row is visible and
                // targeting one by id updates/deletes nothing.
                foreach ($foreignIds as $foreignId) {
                    $this->assertNull(
                        IsolatedRecord::query()->find($foreignId),
                        'A foreign row must not be found under the resolved tenant.'
                    );

                    $this->assertSame(
                        0,
                        IsolatedRecord::query()->whereKey($foreignId)->update(['label' => 'hijacked']),
                        'A scoped update targeting a foreign row must affect nothing.'
                    );

                    $this->assertSame(
                        0,
                        IsolatedRecord::query()->whereKey($foreignId)->delete(),
                        'A scoped delete targeting a foreign row must affect nothing.'
                    );
                }

                // DELETE across the whole table under the scope removes only the
                // resolved Company's rows.
                $deleted = IsolatedRecord::query()->delete();
                $this->assertSame(
                    count($resolvedIds) + 1,
                    $deleted,
                    'A scoped bulk delete must remove only resolved-Company rows.'
                );

                // Every foreign row is byte-for-byte unchanged after all of the
                // above read/write/update/delete attempts.
                foreach ($foreignIds as $foreignId) {
                    $current = IsolatedRecord::withoutGlobalScopes()->find($foreignId);

                    $this->assertNotNull($current, 'A foreign row must survive scoped operations.');
                    $this->assertSame(
                        $snapshot[$foreignId],
                        $current->getAttributes(),
                        'A foreign row must be byte-for-byte unchanged.'
                    );
                }
            });
    }
}

/**
 * Test-only Company-owned model. Uses the production trait so this property
 * test exercises the real global scope and auto-fill behaviour shared by every
 * Company-owned model (events, orders, tickets, scan lookups, GDPR ops).
 */
class IsolatedRecord extends Model
{
    use BelongsToCompany;

    protected $table = 'isolated_records';

    protected $guarded = [];
}
