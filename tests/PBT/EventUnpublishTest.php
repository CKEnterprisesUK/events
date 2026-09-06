<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Property-based test for the unpublish behaviour (feature Property 2).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * The rule under test: unpublishing is unconditional. `Event::unpublish()`
 * sets `is_published` to false for any Event regardless of its state — in
 * particular regardless of whether the Event has unmet publish blockers
 * (missing `starts_at` and/or no Ticket_Type). (Requirement 1.3)
 */
class EventUnpublishTest extends PbtTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 2: Unpublish is unconditional — for any Event, in any state
     * (including states with unmet publish blockers), `unpublish()` sets
     * `is_published` to false.
     *
     * **Validates: Requirements 1.3**
     */
    // Feature: event-management-and-reporting, Property 2: Unpublish is unconditional
    public function test_unpublish_is_unconditional(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // Whether the Event has a non-null starts_at.
            Generator\bool(),
            // Whether the Event has at least one Ticket_Type.
            Generator\bool(),
            // Whether the Event starts out published.
            Generator\bool(),
        )
            ->then(function (bool $hasStartDate, bool $hasTicketType, bool $startsPublished): void {
                // Build a tenant-scoped Event across varied states. The factory
                // auto-creates the owning Company. starts_at and is_published
                // are driven by the generated flags so we cover events with and
                // without unmet publish blockers, published or not.
                $event = Event::factory()->create([
                    'starts_at' => $hasStartDate ? now()->addWeek() : null,
                    'is_published' => $startsPublished,
                ]);

                // Establish the Event's Company as the active tenant so any
                // tenant-scoped relation reads resolve against this Company.
                app(TenantContext::class)->setCompany($event->company);

                // Conditionally add a Ticket_Type belonging to this Event.
                if ($hasTicketType) {
                    TicketType::factory()->forEvent($event)->create();
                }

                // Sanity: the generated state may or may not be publishable —
                // the property must hold either way.
                $event->refresh();

                // Unpublish unconditionally.
                $event->unpublish();

                // The in-memory model reflects the unpublished state.
                $this->assertFalse(
                    $event->is_published,
                    sprintf(
                        'is_published should be false after unpublish() (hasStartDate=%s, hasTicketType=%s, startsPublished=%s)',
                        $hasStartDate ? 'true' : 'false',
                        $hasTicketType ? 'true' : 'false',
                        $startsPublished ? 'true' : 'false',
                    ),
                );

                // The persisted state, re-read from the database, is unpublished.
                $this->assertFalse(
                    $event->fresh()->isPublished(),
                    'A freshly read Event must report isPublished() === false after unpublish()',
                );
            });
    }
}
