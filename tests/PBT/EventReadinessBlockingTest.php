<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\EventReadiness;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the readiness checklist's blocking items (feature
 * Property 4).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The rule under test: the checklist's blocking items are always exactly the
 * publish prerequisites ({starts_at, ticket_types, shared_pool_capacity,
 * payments}); the venue item is never blocking; and each
 * blocking item's satisfied state agrees with Event::publishBlockers() — the
 * single source of truth the publish controller enforces — so presentation and
 * enforcement never drift. (Requirements 2.3, 2.4)
 */
class EventReadinessBlockingTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 4: Blocking checklist items match the publish source of truth —
     * for any combination of (has start date, has ticket type), the set of
     * blocking items is exactly {starts_at, ticket_types, shared_pool_capacity,
     * payments}, the venue item is never blocking, and every
     * blocking item agrees with publishBlockers().
     *
     * **Validates: Requirements 2.3, 2.4**
     */
    // Feature: event-management-and-reporting, Property 4: Blocking checklist items match the publish source of truth
    public function test_blocking_checklist_items_match_the_publish_source_of_truth(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Whether the Event has a non-null starts_at.
            Generator\bool(),
            // Whether the Event has at least one Ticket_Type.
            Generator\bool(),
        )
            ->then(function (bool $hasStartDate, bool $hasTicketType): void {
                // Build a tenant-scoped Event with starts_at set or null per the
                // generated flag. The factory auto-creates the owning Company.
                $event = Event::factory()->create([
                    'starts_at' => $hasStartDate ? now()->addWeek() : null,
                ]);

                // Establish the Event's Company as the active tenant so the
                // global TenantScope on ticketTypes()->exists() (read inside
                // publishBlockers()) resolves against this Company's rows rather
                // than the unsatisfiable no-tenant predicate.
                app(TenantContext::class)->setCompany($event->company);

                // Conditionally add a Ticket_Type belonging to this Event.
                if ($hasTicketType) {
                    TicketType::factory()->forEvent($event)->create();
                }

                // Re-read from the database so publishBlockers() (used by the
                // readiness service) evaluates against persisted rows.
                $event->refresh();

                $items = app(EventReadiness::class)->checklist($event)->items();

                // Index checklist items by key for lookups below.
                $byKey = [];
                foreach ($items as $item) {
                    $byKey[$item->key] = $item;
                }

                // The set of blocking item keys must be exactly the two publish
                // prerequisites.
                $blockingKeys = [];
                foreach ($items as $item) {
                    if ($item->blocking === true) {
                        $blockingKeys[] = $item->key;
                    }
                }
                sort($blockingKeys);

                // Sorted to match the sort() applied to $blockingKeys above.
                $expectedBlockingKeys = ['payments', 'shared_pool_capacity', 'starts_at', 'ticket_types'];

                $this->assertSame(
                    $expectedBlockingKeys,
                    $blockingKeys,
                    'blocking checklist items must be exactly {starts_at, ticket_types, shared_pool_capacity, payments}',
                );

                // The venue item is advisory only — never blocking.
                $this->assertFalse(
                    $byKey['venue']->blocking,
                    "the 'venue' item must never be blocking",
                );

                // Each blocking item must agree with publishBlockers(): unmet
                // blocking items appear as blocker keys, satisfied ones do not.
                $blockers = $event->publishBlockers();

                foreach ($items as $item) {
                    if ($item->blocking !== true) {
                        continue;
                    }

                    if (! $item->satisfied) {
                        $this->assertArrayHasKey(
                            $item->key,
                            $blockers,
                            sprintf(
                                "unmet blocking item '%s' must be present in publishBlockers()",
                                $item->key,
                            ),
                        );
                    } else {
                        $this->assertArrayNotHasKey(
                            $item->key,
                            $blockers,
                            sprintf(
                                "satisfied blocking item '%s' must NOT be present in publishBlockers()",
                                $item->key,
                            ),
                        );
                    }
                }
            });
    }
}
