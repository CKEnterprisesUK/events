<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the publish gate (feature Property 1).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The rule under test: an Event is publishable if and only if ALL publish
 * prerequisites are met — a non-null `starts_at`, at least one Ticket_Type, and
 * (when any Ticket_Type is paid) a Stripe-ready Company — and
 * `publishBlockers()` reports exactly the unmet prerequisites, keyed
 * `starts_at`, `ticket_types` and/or `payments`. This test uses a free ticket
 * type so the payments rule is never triggered; the paid-ticket gate is
 * exercised in {@see PaidTicketPublishGateTest}. (Requirements 1.1, 1.2, 11.5)
 */
class EventPublishGateTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 1: Publish gate is exactly the blocker predicate — for any
     * combination of (has start date, has ticket type), `isPublishable()` is
     * true iff both prerequisites are met, and `publishBlockers()` keys are
     * exactly the unmet prerequisites.
     *
     * **Validates: Requirements 1.1, 1.2**
     */
    // Feature: event-management-and-reporting, Property 1: Publish gate is exactly the blocker predicate
    public function test_publish_gate_is_exactly_the_blocker_predicate(): void
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

                // Conditionally add a FREE Ticket_Type belonging to this Event.
                // Free tickets never trigger the payments blocker, keeping this
                // property focused on the starts_at + ticket_types predicate.
                if ($hasTicketType) {
                    TicketType::factory()->forEvent($event)->free()->create();
                }

                // Re-read from the database so publishBlockers() evaluates the
                // ticketTypes() relation against persisted rows.
                $event->refresh();

                $expectedPublishable = $hasStartDate && $hasTicketType;

                $this->assertSame(
                    $expectedPublishable,
                    $event->isPublishable(),
                    sprintf(
                        'hasStartDate=%s, hasTicketType=%s: isPublishable should be %s',
                        $hasStartDate ? 'true' : 'false',
                        $hasTicketType ? 'true' : 'false',
                        $expectedPublishable ? 'true' : 'false',
                    ),
                );

                $blockers = $event->publishBlockers();

                // The starts_at blocker is present iff the start date is unmet.
                $this->assertSame(
                    ! $hasStartDate,
                    array_key_exists('starts_at', $blockers),
                    "'starts_at' blocker presence should track a missing start date",
                );

                // The ticket_types blocker is present iff no Ticket_Type exists.
                $this->assertSame(
                    ! $hasTicketType,
                    array_key_exists('ticket_types', $blockers),
                    "'ticket_types' blocker presence should track a missing ticket type",
                );

                // No blocker keys other than the two known prerequisites.
                $expectedKeys = [];
                if (! $hasStartDate) {
                    $expectedKeys[] = 'starts_at';
                }
                if (! $hasTicketType) {
                    $expectedKeys[] = 'ticket_types';
                }

                sort($expectedKeys);
                $actualKeys = array_keys($blockers);
                sort($actualKeys);

                $this->assertSame(
                    $expectedKeys,
                    $actualKeys,
                    'publishBlockers() keys must be exactly the unmet prerequisites',
                );
            });
    }
}
