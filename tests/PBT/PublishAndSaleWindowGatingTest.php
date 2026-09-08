<?php

namespace Tests\PBT;

use App\Models\Event;
use App\Models\TicketType;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Property-based test for publish and sale-window gating (design Property 8).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database.
 *
 * REGRESSION — intentional semantic change (event-authoring-redesign, task 1.7,
 * Requirements 8.7, 8.8).
 * ---------------------------------------------------------------------------
 * The sale window is NO LONGER "both bounds required, [sale_starts_at,
 * sale_ends_at)". A null bound no longer closes the window; it relaxes it. The
 * window is now the half-open EFFECTIVE interval [effectiveStart, effectiveEnd):
 *   - effectiveStart = sale_starts_at, or "no lower bound" when null
 *     (publication is the real lower gate, enforced by isPurchasableAt), so a
 *     null start means "on sale from publication". (Requirement 8.7)
 *   - effectiveEnd   = sale_ends_at, else the Event start time, else "no upper
 *     bound". A null end therefore means "on sale until the event starts".
 *     (Requirement 8.8)
 * The old tests assumed a null bound meant "never on sale"; that assumption is
 * deliberately replaced here so the change is intentional, not an accidental
 * break.
 *
 * The rule under test: a Customer may view AND purchase a Ticket_Type if and
 * only if the Event is published AND the current time lies within the effective
 * half-open sale window [effectiveStart, effectiveEnd). Equivalently:
 *   - an unpublished Event blocks view/purchase regardless of time
 *     (Requirement 5.5);
 *   - before the effective start the sale has not started (Requirement 6.4);
 *   - at or after the effective end the sale has ended (Requirement 6.5).
 *
 * This property generates random combinations of Event published/unpublished,
 * null/explicit sale_starts_at and sale_ends_at, and current time positioned
 * relative to the effective window — before start, exactly at start (open),
 * strictly within, exactly at end (closed), and after end — and asserts that
 * TicketType::isPurchasableAt returns true iff (published AND now in effective
 * window). Carbon::setTestNow pins "now" so the boundary comparisons are exact
 * and deterministic.
 */
class PublishAndSaleWindowGatingTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The fixed sale window used across the property. "now" is generated as an
     * offset in seconds relative to this window so the interesting boundaries
     * (start and end) are hit exactly.
     */
    private const WINDOW_START = '2024-06-01 09:00:00';

    private const WINDOW_END = '2024-06-08 09:00:00';

    /**
     * The Event start time. It sits strictly after the explicit window end so
     * that, when sale_ends_at is null, the effective upper bound (the event
     * start) is distinguishable from the explicit end.
     */
    private const EVENT_START = '2024-06-15 09:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Generate a "now" instant expressed as a whole-second offset from the sale
     * window start, drawn tightly around every boundary that can flip the iff
     * decision (the explicit start, the explicit end, and the event start used
     * as the fallback upper bound) plus wide bands well outside the window.
     */
    private function nowOffsetSecondsGenerator(): Generator
    {
        // diffInSeconds returns a float in modern Carbon; cast to int because
        // Eris' Generator\choose requires integer bounds.
        $windowLength = (int) Carbon::parse(self::WINDOW_END)
            ->diffInSeconds(Carbon::parse(self::WINDOW_START));
        $eventStartOffset = (int) Carbon::parse(self::EVENT_START)
            ->diffInSeconds(Carbon::parse(self::WINDOW_START));

        return Generator\oneOf(
            // A band straddling the explicit start boundary (offset 0).
            Generator\choose(-120, 120),
            // A band straddling the explicit end boundary.
            Generator\choose($windowLength - 120, $windowLength + 120),
            // A band straddling the event-start fallback boundary.
            Generator\choose($eventStartOffset - 120, $eventStartOffset + 120),
            // Clearly before the window opens.
            Generator\choose(-30 * 24 * 60 * 60, -121),
            // Clearly within the explicit window.
            Generator\choose(121, $windowLength - 121),
            // Between the explicit end and the event start.
            Generator\choose($windowLength + 121, $eventStartOffset - 121),
            // Clearly after the event start.
            Generator\choose($eventStartOffset + 121, $eventStartOffset + 30 * 24 * 60 * 60),
        );
    }

    /**
     * Property 8: Publish and sale-window gating — for any combination of Event
     * publication state, null/explicit sale bounds, and current time, a
     * Customer may view/purchase a Ticket_Type if and only if the Event is
     * published and the current time is within the effective half-open sale
     * window [effectiveStart, effectiveEnd).
     *
     * **Validates: Requirements 5.5, 6.4, 6.5, 8.7, 8.8**
     */
    // Feature: event-authoring-redesign, Property 8: Publish and sale-window gating — Customer may view/purchase a Ticket_Type iff Event published and now ∈ effective window [effectiveStart, effectiveEnd)
    public function test_purchasable_iff_published_and_now_within_effective_sale_window(): void
    {
        $windowStart = Carbon::parse(self::WINDOW_START);
        $windowEnd = Carbon::parse(self::WINDOW_END);
        $eventStart = Carbon::parse(self::EVENT_START);

        $event = Event::factory()->create([
            'is_published' => true,
            'starts_at' => $eventStart,
        ]);

        $this->forAll(
            Generator\bool(),
            Generator\bool(),
            Generator\bool(),
            $this->nowOffsetSecondsGenerator(),
        )
            ->then(function (
                bool $published,
                bool $explicitStart,
                bool $explicitEnd,
                int $offsetSeconds,
            ) use ($event, $windowStart, $windowEnd, $eventStart): void {
                $now = $windowStart->copy()->addSeconds($offsetSeconds);
                Carbon::setTestNow($now);

                // A null bound no longer closes the window (the semantic change).
                // effectiveStart = explicit start, else no lower bound.
                // effectiveEnd   = explicit end, else the event start.
                $effectiveStart = $explicitStart ? $windowStart : null;
                $effectiveEnd = $explicitEnd ? $windowEnd : $eventStart;

                $event->update(['is_published' => $published]);

                $ticketType = TicketType::factory()->forEvent($event)->create([
                    'sale_starts_at' => $effectiveStart,
                    'sale_ends_at' => $explicitEnd ? $windowEnd : null,
                ]);
                $ticketType->setRelation('event', $event->fresh());

                $afterStart = $effectiveStart === null || $now->greaterThanOrEqualTo($effectiveStart);
                $beforeEnd = $effectiveEnd === null || $now->lessThan($effectiveEnd);
                $withinWindow = $afterStart && $beforeEnd;
                $expected = $published && $withinWindow;

                // The pure sale-window helper reflects only the effective
                // [start, end) interval, independent of publication.
                $this->assertSame(
                    $withinWindow,
                    $ticketType->isOnSaleAt($now),
                    sprintf(
                        'isOnSaleAt at offset %ds (explicitStart=%s, explicitEnd=%s) should be %s',
                        $offsetSeconds,
                        $explicitStart ? 'true' : 'false',
                        $explicitEnd ? 'true' : 'false',
                        $withinWindow ? 'true' : 'false',
                    ),
                );

                // The combined gate: purchasable iff published AND in-window.
                $this->assertSame(
                    $expected,
                    $ticketType->isPurchasableAt($now),
                    sprintf(
                        'published=%s, offset=%ds (within=%s): purchasable should be %s',
                        $published ? 'true' : 'false',
                        $offsetSeconds,
                        $withinWindow ? 'true' : 'false',
                        $expected ? 'true' : 'false',
                    ),
                );
            });
    }

    /**
     * REGRESSION (intentional): a fully unbounded Ticket_Type (both sale bounds
     * null) on a published Event is on sale for the entire run-up to the event
     * start, and ends exactly when the event starts.
     *
     * Under the OLD semantics this configuration was "never on sale" because a
     * null bound closed the window. Under the new effective-interval semantics
     * (Requirements 8.7, 8.8) the effective window is [publication, event start).
     */
    public function test_null_bounds_are_on_sale_until_the_event_starts(): void
    {
        $eventStart = Carbon::parse(self::EVENT_START);
        $event = Event::factory()->create([
            'is_published' => true,
            'starts_at' => $eventStart,
        ]);
        $ticketType = TicketType::factory()->forEvent($event)->create([
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        $ticketType->setRelation('event', $event->fresh());

        // Well before the event starts: on sale (no lower bound to block it).
        $beforeStart = $eventStart->copy()->subMonth();
        $this->assertTrue($ticketType->isOnSaleAt($beforeStart));
        $this->assertTrue($ticketType->isPurchasableAt($beforeStart));

        // One second before the event starts: still on sale.
        $justBefore = $eventStart->copy()->subSecond();
        $this->assertTrue($ticketType->isOnSaleAt($justBefore));

        // Exactly at the event start: half-open interval closes, sale ended.
        $this->assertFalse($ticketType->isOnSaleAt($eventStart->copy()));
        $this->assertFalse($ticketType->isPurchasableAt($eventStart->copy()));
    }
}
