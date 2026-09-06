<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\EventReadiness;
use App\Services\Events\CapacityComparison;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the readiness checklist (feature Property 3).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The rule under test: for any Event, EventReadiness::checklist() returns
 * exactly the items keyed {name, starts_at, venue, ticket_types, capacity} in
 * that fixed order, and each item's `satisfied` flag equals the predicate
 * evaluated independently against the Event's current persisted state.
 * (Requirements 2.1, 2.2)
 */
class EventReadinessChecklistTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 3: Checklist reflects event state — for any generated Event, the
     * checklist contains exactly {name, starts_at, venue, ticket_types,
     * capacity} in order and each `satisfied` flag matches the predicate on the
     * Event.
     *
     * **Validates: Requirements 2.1, 2.2**
     */
    // Feature: event-management-and-reporting, Property 3: Checklist reflects event state
    public function test_checklist_reflects_event_state(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Whether the Event has a non-null starts_at.
            Generator\bool(),
            // Whether the Event has a non-empty venue.
            Generator\bool(),
            // Whether the Event has at least one Ticket_Type.
            Generator\bool(),
            // How the Event's overall capacity relates to the ticket-type sum:
            // 0 => null (unlimited), 1 => below sum, 2 => equal, 3 => above sum.
            Generator\choose(0, 3),
        )
            ->then(function (bool $hasStartDate, bool $hasVenue, bool $hasTicketType, int $capacityMode): void {
                // A fixed per-type capacity so the ticket-type sum is
                // predictable when relating the Event capacity to it.
                $typeCapacity = 100;
                $typesSum = $hasTicketType ? $typeCapacity : 0;

                $eventCapacity = match ($capacityMode) {
                    0 => null,
                    1 => $typesSum - 1,   // below the sum  => EVENT_BINDS
                    2 => $typesSum,       // equal          => BALANCED
                    default => $typesSum + 1, // above the sum  => TYPES_BIND
                };

                // Build a tenant-scoped Event. The factory auto-creates the
                // owning Company and always sets a non-empty name.
                $event = Event::factory()->create([
                    'starts_at' => $hasStartDate ? now()->addWeek() : null,
                    'venue' => $hasVenue ? 'Grand Hall' : '',
                    'capacity' => $eventCapacity,
                ]);

                // Establish the Event's Company as the active tenant so the
                // global TenantScope on ticketTypes() (read inside checklist()
                // via publishBlockers() and capacity()) resolves against this
                // Company's rows rather than the no-tenant predicate.
                app(TenantContext::class)->setCompany($event->company);

                if ($hasTicketType) {
                    TicketType::factory()->forEvent($event)->create([
                        'capacity' => $typeCapacity,
                    ]);
                }

                // Re-read so the checklist evaluates persisted state.
                $event->refresh();

                $items = app(EventReadiness::class)->checklist($event)->items();

                // The item keys must be exactly these five, in this order.
                $this->assertSame(
                    ['name', 'starts_at', 'venue', 'ticket_types', 'capacity'],
                    array_map(fn ($item) => $item->key, $items),
                    'checklist items must be exactly {name, starts_at, venue, ticket_types, capacity} in order',
                );

                // Independently computed expected `satisfied` per key.
                $expectedCapacitySane = (new CapacityComparison(
                    eventCapacity: $eventCapacity,
                    typesSum: $typesSum,
                ))->isSane();

                $expected = [
                    'name' => $event->name !== null && trim($event->name) !== '',
                    'starts_at' => $event->starts_at !== null,
                    'venue' => $event->venue !== null && trim($event->venue) !== '',
                    'ticket_types' => $event->ticketTypes()->exists(),
                    'capacity' => $expectedCapacitySane,
                ];

                foreach ($items as $item) {
                    $this->assertSame(
                        $expected[$item->key],
                        $item->satisfied,
                        sprintf(
                            "item '%s' satisfied should be %s (hasStartDate=%s, hasVenue=%s, hasTicketType=%s, capacityMode=%d)",
                            $item->key,
                            $expected[$item->key] ? 'true' : 'false',
                            $hasStartDate ? 'true' : 'false',
                            $hasVenue ? 'true' : 'false',
                            $hasTicketType ? 'true' : 'false',
                            $capacityMode,
                        ),
                    );
                }
            });
    }
}
