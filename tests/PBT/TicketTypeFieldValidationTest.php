<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Models\User;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

/**
 * Property-based test for Ticket_Type field and sale-window validation
 * (design Property 9).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy. Runs against the real MySQL
 * test database and drives the real production path: an authenticated Admin
 * POSTs to the nested Ticket_Type create route so the property exercises the
 * exact validation the {@see \App\Http\Controllers\TicketTypeController} applies
 * in production.
 *
 * The rule under test (Requirements 6.1, 6.2, 6.3, 6.9): an input is accepted
 * if and only if
 *   - name is 1–100 characters, AND
 *   - price is a decimal in [0.00, 999,999.99] with at most two decimal places
 *     (0 = free — Requirement 6.3), AND
 *   - capacity is an integer in [1, 1,000,000], AND
 *   - the sale window end is strictly after the sale window start
 *     (Requirement 6.9).
 * A rejected input must leave no ticket_types row created; an accepted input
 * must round-trip (name/capacity persist verbatim and the decimal price maps to
 * the expected integer minor units).
 *
 * The generators are written to hit every field boundary directly: name length
 * incl. 0/100/101; price incl. negative/0/max/over-max and >2 decimal places;
 * capacity incl. 0/1/1,000,000/1,000,001; and sale windows with end < start,
 * end == start, and end > start.
 */
class TicketTypeFieldValidationTest extends PbtTestCase
{
    use RefreshDatabase;

    /** Maximum price in minor units: 999,999.99 => 99,999,999. (Requirement 6.1) */
    private const MAX_PRICE_MINOR = 99_999_999;

    /** Maximum capacity. (Requirement 6.1) */
    private const MAX_CAPACITY = 1_000_000;

    /** Fixed anchor for sale-window generation so boundaries are exact. */
    private const SALE_START = '2024-06-01 09:00:00';

    private User $admin;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        // A single Admin + owned Event reused across iterations. Created rows
        // are removed after each accepted input (Eloquent delete only — no DDL
        // or TRUNCATE, per the test-infra rule) so the per-Event 1–50 ceiling
        // is never reached and "no row created" assertions stay clean.
        $this->admin = User::factory()->admin()->create();
        $this->event = Event::factory()
            ->for(Company::find($this->admin->company_id))
            ->create();
    }

    /**
     * Candidate name generator spanning the 1–100 boundary: the empty string
     * (invalid), a single char (min valid), exactly 100 (max valid), and 101
     * (over-length, invalid), plus random lengths straddling the limit.
     */
    private function nameGenerator(): Generator
    {
        return Generator\oneOf(
            Generator\elements('', 'a', str_repeat('a', 100), str_repeat('a', 101)),
            Generator\map(
                fn (int $len): string => str_repeat('a', max(0, $len)),
                Generator\choose(0, 105),
            ),
        );
    }

    /**
     * Candidate price generator (submitted as the decimal string the form
     * posts). Covers negative, 0 (free), the 999,999.99 upper bound, just over
     * the bound, and values with more than two decimal places (invalid), plus
     * random in-range decimals.
     */
    private function priceGenerator(): Generator
    {
        return Generator\oneOf(
            Generator\elements(
                '-1',           // negative -> invalid
                '-0.01',        // negative -> invalid
                '0',            // free -> valid
                '0.00',         // free -> valid
                '12.50',        // typical -> valid
                '999999.99',    // max -> valid
                '1000000.00',   // over max -> invalid
                '1000000',      // over max -> invalid
                '10.123',       // >2 dp -> invalid
                '5.5',          // 1 dp -> valid
            ),
            // Random whole-cent amounts formatted to two decimals; the choose()
            // range extends just past the max minor-unit bound.
            Generator\map(
                fn (int $minor): string => number_format($minor / 100, 2, '.', ''),
                Generator\choose(-100, self::MAX_PRICE_MINOR + 100),
            ),
        );
    }

    /**
     * Candidate capacity generator (submitted as the form posts it). Covers 0
     * (invalid), 1 (min valid), 1,000,000 (max valid), 1,000,001 (over max),
     * plus random values straddling both ends.
     */
    private function capacityGenerator(): Generator
    {
        return Generator\oneOf(
            Generator\elements(0, 1, 100, self::MAX_CAPACITY, self::MAX_CAPACITY + 1),
            Generator\choose(-5, self::MAX_CAPACITY + 5),
        );
    }

    /**
     * Sale-window end offset (seconds relative to a fixed start), covering
     * strictly-before (invalid), equal (invalid — not strictly after), and
     * strictly-after (valid), including the exact one-second boundaries.
     */
    private function endOffsetSecondsGenerator(): Generator
    {
        return Generator\oneOf(
            Generator\elements(-3600, -1, 0, 1, 3600),
            Generator\choose(-7200, 7200),
        );
    }

    /**
     * The independently-computed oracle mirroring the controller's rules.
     */
    private function expectedAccepted(string $name, string $price, int $capacity, int $endOffset): bool
    {
        $nameLen = strlen($name);
        $nameValid = $nameLen >= 1 && $nameLen <= 100;

        // Price must be a decimal with at most two decimal places, in
        // [0, 999,999.99]. Match the numeric/decimal:0,2 rule the controller
        // applies to the posted string.
        $priceValid = preg_match('/^\d+(\.\d{1,2})?$/', $price) === 1
            && (float) $price >= 0.0
            && (float) $price <= 999999.99;

        $capacityValid = $capacity >= 1 && $capacity <= self::MAX_CAPACITY;

        // end strictly after start.
        $windowValid = $endOffset > 0;

        return $nameValid && $priceValid && $capacityValid && $windowValid;
    }

    /**
     * Property 9: Ticket-type field and sale-window validation — the Platform
     * accepts a Ticket_Type input if and only if the name is 1–100 characters,
     * the price is 0.00–999,999.99, the capacity is 1–1,000,000, and the sale
     * window end is strictly after the start; accepted inputs round-trip and
     * rejected inputs create no Ticket_Type.
     *
     * **Validates: Requirements 6.1, 6.2, 6.3, 6.9**
     */
    // Feature: event-ticketing-platform, Property 9: Ticket-type field and sale-window validation — accept iff name 1–100, price 0.00–999,999.99, capacity 1–1,000,000, end strictly after start; accepted inputs round-trip
    public function test_ticket_type_accepted_iff_all_fields_and_window_valid(): void
    {
        $saleStart = Carbon::parse(self::SALE_START);
        $route = "/dashboard/events/{$this->event->id}/ticket-types";

        $this->forAll(
            $this->nameGenerator(),
            $this->priceGenerator(),
            $this->capacityGenerator(),
            $this->endOffsetSecondsGenerator(),
        )
            ->then(function (string $name, string $price, int $capacity, int $endOffset) use ($route, $saleStart): void {
                $saleEnds = $saleStart->copy()->addSeconds($endOffset);

                $payload = [
                    'name' => $name,
                    'price' => $price,
                    'capacity' => $capacity,
                    'sale_starts_at' => $saleStart->toDateTimeString(),
                    'sale_ends_at' => $saleEnds->toDateTimeString(),
                ];

                $expectedAccepted = $this->expectedAccepted($name, $price, $capacity, $endOffset);

                $response = $this->actingAs($this->admin)
                    ->from('/dashboard')
                    ->post($route, $payload);

                $context = sprintf(
                    'name(len=%d)=%s price=%s capacity=%d endOffset=%d -> expected %s',
                    strlen($name),
                    var_export($name, true),
                    $price,
                    $capacity,
                    $endOffset,
                    $expectedAccepted ? 'accept' : 'reject',
                );

                $created = TicketType::withoutGlobalScopes()
                    ->where('event_id', $this->event->id)
                    ->first();

                if ($expectedAccepted) {
                    // Accepted: a redirect back to the index and exactly one
                    // persisted row that round-trips the submitted values.
                    $response->assertRedirect(
                        route('dashboard.events.ticket-types.index', $this->event)
                    );
                    $response->assertSessionHasNoErrors();

                    $this->assertNotNull($created, "Accepted input created no row: {$context}");
                    $this->assertSame($name, $created->name, "name mismatch: {$context}");
                    $this->assertSame($capacity, $created->capacity, "capacity mismatch: {$context}");
                    $this->assertSame(
                        (int) round(((float) $price) * 100),
                        $created->price_minor,
                        "price_minor mismatch: {$context}",
                    );
                    $this->assertSame(
                        $saleStart->toDateTimeString(),
                        $created->sale_starts_at->toDateTimeString(),
                        "sale_starts_at mismatch: {$context}",
                    );
                    $this->assertSame(
                        $saleEnds->toDateTimeString(),
                        $created->sale_ends_at->toDateTimeString(),
                        "sale_ends_at mismatch: {$context}",
                    );

                    // Remove the row (Eloquent delete only) so the next
                    // iteration starts from a clean, empty Event.
                    $created->forceDelete();
                } else {
                    // Rejected: validation errors and no Ticket_Type created.
                    $response->assertSessionHasErrors();
                    $this->assertNull($created, "Rejected input created a row: {$context}");
                }
            });
    }
}
