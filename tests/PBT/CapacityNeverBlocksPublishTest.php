<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\Events\CapacityComparison;
use App\Services\EventReadiness;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test that capacity warnings never block publishing (feature
 * Property 6).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The rule under test: for any Event whose two real publish prerequisites are
 * satisfied — a non-null `starts_at` AND at least one Ticket_Type —
 * `publishBlockers()` is empty regardless of the capacity comparison state
 * (unlimited / event_binds / balanced / types_bind), so a capacity mismatch is
 * advisory only and never prevents publishing. (Requirement 3.4)
 */
class CapacityNeverBlocksPublishTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * Known ticket-type capacity. Event capacities are chosen relative to this
     * value so every CapacityComparison state is reachable:
     *  - null      => UNLIMITED
     *  - below 100 => EVENT_BINDS
     *  - 100       => BALANCED
     *  - above 100 => TYPES_BIND
     */
    private const TYPE_CAPACITY = 100;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 6: Capacity warnings never block publishing — for any Event with
     * both real prerequisites met, `publishBlockers()` is empty regardless of
     * the capacity comparison state.
     *
     * **Validates: Requirements 3.4**
     */
    // Feature: event-management-and-reporting, Property 6: Capacity warnings never block publishing
    public function test_capacity_warnings_never_block_publishing(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Event capacity relative to the fixed ticket-type capacity (100):
            // null (unlimited), 50 (below), 100 (equal), 200 (above), plus an
            // integer spanning the full range so classification genuinely
            // varies across below / equal / above.
            Generator\oneOf(
                Generator\constant(null),
                Generator\choose(1, 250),
            ),
        )
            ->then(function (?int $capacity): void {
                // Both real prerequisites are ALWAYS satisfied: a non-null
                // starts_at and (below) at least one Ticket_Type.
                $event = Event::factory()->create([
                    'starts_at' => now()->addWeek(),
                    'capacity' => $capacity,
                ]);

                // Establish the Event's Company as the active tenant so the
                // global TenantScope on ticketTypes() (read inside
                // publishBlockers() and EventReadiness) resolves against this
                // Company's rows.
                app(TenantContext::class)->setCompany($event->company);

                // Always attach at least one FREE Ticket_Type with a known
                // capacity so the ticket-type prerequisite is met and the
                // comparison sum is deterministic. Free tickets keep the
                // payments blocker out, so this property stays about capacity
                // only.
                TicketType::factory()->forEvent($event)->free()->create([
                    'capacity' => self::TYPE_CAPACITY,
                ]);

                // Re-read so publishBlockers() evaluates against persisted rows.
                $event->refresh();

                // Core property: with both prerequisites met, there are no
                // publish blockers whatever the capacity configuration.
                $this->assertSame(
                    [],
                    $event->publishBlockers(),
                    sprintf(
                        'publishBlockers() must be empty when both prerequisites are met (capacity=%s)',
                        $capacity === null ? 'null' : (string) $capacity,
                    ),
                );

                // Confirm the capacity state genuinely varies with the chosen
                // capacity, so the property is exercised across all four states.
                $expectedState = match (true) {
                    $capacity === null => CapacityComparison::UNLIMITED,
                    $capacity < self::TYPE_CAPACITY => CapacityComparison::EVENT_BINDS,
                    $capacity > self::TYPE_CAPACITY => CapacityComparison::TYPES_BIND,
                    default => CapacityComparison::BALANCED,
                };

                $this->assertSame(
                    $expectedState,
                    app(EventReadiness::class)->capacity($event)->state(),
                    'capacity comparison state should reflect the chosen capacity',
                );
            });
    }
}
