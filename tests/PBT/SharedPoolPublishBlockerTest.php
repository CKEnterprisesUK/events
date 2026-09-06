<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the shared-pool-without-overall-capacity publish
 * blocker (feature Property 6).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The rule under test (in {@see Event::publishBlockers()}): the
 * `shared_pool_capacity` blocker is present if and only if the Event has no
 * overall capacity (`capacity === null`) AND at least one of its Ticket_Types
 * is shared-pool. While that blocker is present, `isPublishable()` is false.
 * (Requirements 3.1, 3.2, 3.4)
 */
class SharedPoolPublishBlockerTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 6: Shared-pool-without-overall-capacity publish blocker — for
     * any combination of (has overall capacity, has a shared-pool type, has a
     * capped type, has a start date), the `shared_pool_capacity` blocker is
     * present iff the Event has no overall capacity AND a shared-pool type
     * exists; and whenever that blocker is present, `isPublishable()` is false.
     *
     * **Validates: Requirements 3.1, 3.2, 3.4**
     */
    // Feature: event-experience-polish, Property 6: Shared-pool-without-overall-capacity publish blocker
    public function test_shared_pool_without_overall_capacity_publish_blocker(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Whether the Event has an overall capacity (positive int) or null.
            Generator\bool(),
            // Whether the Event has at least one shared-pool Ticket_Type.
            Generator\bool(),
            // Whether the Event has a capped Ticket_Type (varies the type mix).
            Generator\bool(),
            // Whether the Event has a non-null starts_at (varies other blockers).
            Generator\bool(),
        )
            ->then(function (
                bool $hasCapacity,
                bool $hasSharedPool,
                bool $hasCappedType,
                bool $hasStartDate
            ): void {
                // Build a tenant-scoped Event with capacity set to a positive
                // int or null, and starts_at set or null, per the flags. The
                // factory auto-creates the owning Company.
                $event = Event::factory()->create([
                    'capacity' => $hasCapacity ? 500 : null,
                    'starts_at' => $hasStartDate ? now()->addWeek() : null,
                ]);

                // Establish the Event's Company as the active tenant so the
                // global TenantScope on ticketTypes() (read inside
                // publishBlockers()) resolves against this Company's rows.
                app(TenantContext::class)->setCompany($event->company);

                // Free ticket types throughout: this property isolates the
                // shared-pool capacity blocker, so the payments blocker (which
                // only fires for paid tickets without Stripe) must never enter.
                if ($hasSharedPool) {
                    TicketType::factory()->forEvent($event)->sharedPool()->free()->create();
                }

                if ($hasCappedType) {
                    TicketType::factory()->forEvent($event)->free()->create();
                }

                // Re-read so publishBlockers() evaluates the ticketTypes()
                // relation against persisted rows.
                $event->refresh();

                $blockers = $event->publishBlockers();

                $expectedBlocker = ! $hasCapacity && $hasSharedPool;

                // The shared_pool_capacity blocker is present iff the Event has
                // no overall capacity AND a shared-pool type exists.
                $this->assertSame(
                    $expectedBlocker,
                    array_key_exists('shared_pool_capacity', $blockers),
                    sprintf(
                        'hasCapacity=%s, hasSharedPool=%s: shared_pool_capacity blocker presence should be %s',
                        $hasCapacity ? 'true' : 'false',
                        $hasSharedPool ? 'true' : 'false',
                        $expectedBlocker ? 'true' : 'false',
                    ),
                );

                // Whenever that blocker is present, the Event is not publishable.
                if ($expectedBlocker) {
                    $this->assertFalse(
                        $event->isPublishable(),
                        'isPublishable() must be false while the shared_pool_capacity blocker is present',
                    );
                }
            });
    }
}
