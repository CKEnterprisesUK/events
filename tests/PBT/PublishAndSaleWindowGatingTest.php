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
 * The rule under test: a Customer may view AND purchase a Ticket_Type if and
 * only if the Event is published AND the current time lies within the
 * half-open sale window [sale_starts_at, sale_ends_at). Equivalently:
 *   - an unpublished Event blocks view/purchase regardless of time
 *     (Requirement 5.5);
 *   - before the sale window start the sale has not started (Requirement 6.4);
 *   - at or after the sale window end the sale has ended (Requirement 6.5).
 *
 * This property generates random combinations of Event published/unpublished
 * and current time positioned relative to a Ticket_Type's sale window — before
 * start, exactly at start (open), strictly within, exactly at end (closed),
 * and after end — and asserts that TicketType::isPurchasableAt returns true iff
 * (published AND now >= start AND now < end). Carbon::setTestNow pins "now" so
 * the boundary comparisons are exact and deterministic.
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Generate a "now" instant expressed as a whole-second offset from the sale
     * window start, drawn tightly around both the start and end boundaries so
     * the property hammers the exact edges where the iff decision flips
     * (including one second before start, exactly at start, one second before
     * end, and exactly at end), with wider bands well outside the window.
     */
    private function nowOffsetSecondsGenerator(): Generator
    {
        // diffInSeconds returns a float in modern Carbon; cast to int because
        // Eris' Generator\choose requires integer bounds.
        $windowLength = (int) Carbon::parse(self::WINDOW_END)
            ->diffInSeconds(Carbon::parse(self::WINDOW_START));

        return Generator\oneOf(
            // A band straddling the start boundary (offset 0): [-120, +120].
            Generator\choose(-120, 120),
            // A band straddling the end boundary: [length-120, length+120].
            Generator\choose($windowLength - 120, $windowLength + 120),
            // Clearly before the window opens.
            Generator\choose(-30 * 24 * 60 * 60, -121),
            // Clearly within the window.
            Generator\choose(121, $windowLength - 121),
            // Clearly after the window closes.
            Generator\choose($windowLength + 121, $windowLength + 30 * 24 * 60 * 60),
        );
    }

    /**
     * Property 8: Publish and sale-window gating — for any combination of Event
     * publication state and current time, a Customer may view/purchase a
     * Ticket_Type if and only if the Event is published and the current time is
     * within the half-open sale window [sale start, sale end).
     *
     * **Validates: Requirements 5.5, 6.4, 6.5**
     */
    // Feature: event-ticketing-platform, Property 8: Publish and sale-window gating — Customer may view/purchase a Ticket_Type iff Event published and now ∈ [sale start, sale end)
    public function test_purchasable_iff_published_and_now_within_sale_window(): void
    {
        $windowStart = Carbon::parse(self::WINDOW_START);
        $windowEnd = Carbon::parse(self::WINDOW_END);

        $event = Event::factory()->create(['is_published' => true]);
        $ticketType = TicketType::factory()->forEvent($event)->create([
            'sale_starts_at' => $windowStart,
            'sale_ends_at' => $windowEnd,
        ]);

        $this->forAll(
            Generator\bool(),
            $this->nowOffsetSecondsGenerator(),
        )
            ->then(function (bool $published, int $offsetSeconds) use ($event, $ticketType, $windowStart, $windowEnd): void {
                $now = $windowStart->copy()->addSeconds($offsetSeconds);
                Carbon::setTestNow($now);

                // Reflect the generated publication state on the persisted Event
                // and refresh the in-memory relation so isPurchasableAt reads it.
                $event->update(['is_published' => $published]);
                $ticketType->setRelation('event', $event->fresh());

                $withinWindow = $now->greaterThanOrEqualTo($windowStart)
                    && $now->lessThan($windowEnd);
                $expected = $published && $withinWindow;

                // The pure sale-window helper reflects only the [start, end)
                // interval, independent of publication.
                $this->assertSame(
                    $withinWindow,
                    $ticketType->isOnSaleAt($now),
                    sprintf(
                        'isOnSaleAt at offset %ds should be %s',
                        $offsetSeconds,
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
}
