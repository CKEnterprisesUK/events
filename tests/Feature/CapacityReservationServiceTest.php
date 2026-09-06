<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientCapacityException;
use App\Models\Company;
use App\Models\Event;
use App\Models\TicketType;
use App\Services\CapacityReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 8.1 — the CapacityReservationService that reserves/releases
 * Ticket_Type capacity inside a DB transaction using SELECT ... FOR UPDATE so
 * concurrent checkouts serialize and never oversell.
 *
 * Runs against the real MySQL test database so the FOR UPDATE row locking and
 * count arithmetic behave exactly as in production.
 *
 * Requirements: 5.6 (overall Event capacity gate), 6.6/6.7/6.8 (per-Ticket_Type
 * capacity, atomic over-request rejection, serialized no-oversell), 10.6/10.7
 * (900s reservation window + release), 18.3 (serialization). The concurrency
 * PBT (8.3) and release PBT (8.4) are covered separately.
 */
class CapacityReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CapacityReservationService
    {
        return app(CapacityReservationService::class);
    }

    /**
     * A paid Ticket_Type belonging to $event with the given capacity and no
     * sold/reserved units yet.
     */
    private function ticketType(Event $event, int $capacity, int $sold = 0, int $reserved = 0): TicketType
    {
        return TicketType::factory()->forEvent($event)->create([
            'capacity' => $capacity,
            'sold_count' => $sold,
            'reserved_count' => $reserved,
        ]);
    }

    // ---- Reserve -------------------------------------------------------------

    public function test_reserve_increments_reserved_count_within_availability(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 100);

        // Requirement 6.6/10.6 — reserving fewer than remaining holds the units.
        $this->service()->reserve($event, [$type->id => 30]);

        $this->assertSame(30, $type->fresh()->reserved_count);
        $this->assertSame(0, $type->fresh()->sold_count);
    }

    public function test_reserve_returns_a_reserved_until_900s_in_the_future(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 10);

        $before = now();
        $reservedUntil = $this->service()->reserve($event, [$type->id => 1]);

        // Requirement 10.6/10.7 — the reservation window is 900 seconds.
        $this->assertEqualsWithDelta(
            $before->copy()->addSeconds(900)->timestamp,
            $reservedUntil->timestamp,
            2
        );
    }

    public function test_reserve_allows_filling_capacity_exactly(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        // 4 already sold, 3 already reserved => 3 remaining of 10.
        $type = $this->ticketType($event, capacity: 10, sold: 4, reserved: 3);

        $this->service()->reserve($event, [$type->id => 3]);

        // Requirement 6.6 — reserving up to the remaining boundary is allowed.
        $this->assertSame(6, $type->fresh()->reserved_count);
    }

    // ---- Over-request rejection (atomic) ------------------------------------

    public function test_reserve_rejects_over_request_and_changes_nothing(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 10, sold: 8, reserved: 0);

        // Requirement 6.7 — requesting more than remaining (2) is rejected whole.
        try {
            $this->service()->reserve($event, [$type->id => 3]);
            $this->fail('Expected InsufficientCapacityException.');
        } catch (InsufficientCapacityException $e) {
            // expected
        }

        // Nothing reserved; counts unchanged.
        $this->assertSame(0, $type->fresh()->reserved_count);
        $this->assertSame(8, $type->fresh()->sold_count);
    }

    public function test_reserve_rejects_whole_multi_type_request_atomically(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $ok = $this->ticketType($event, capacity: 100);       // plenty
        $tight = $this->ticketType($event, capacity: 5, sold: 4); // only 1 remaining

        // Requirement 6.7 — one over-requested type rejects the entire request;
        // the type that would have fit must NOT be reserved.
        $this->expectException(InsufficientCapacityException::class);

        try {
            $this->service()->reserve($event, [$ok->id => 10, $tight->id => 2]);
        } finally {
            $this->assertSame(0, $ok->fresh()->reserved_count);
            $this->assertSame(0, $tight->fresh()->reserved_count);
        }
    }

    // ---- Overall Event capacity gate ----------------------------------------

    public function test_reserve_enforces_overall_event_capacity_across_types(): void
    {
        // Event capped at 5 overall, but two types each with capacity 10.
        $event = Event::factory()->create(['capacity' => 5]);
        $a = $this->ticketType($event, capacity: 10);
        $b = $this->ticketType($event, capacity: 10);

        // Requirement 5.6 — 3 + 3 = 6 exceeds the Event's overall 5.
        try {
            $this->service()->reserve($event, [$a->id => 3, $b->id => 3]);
            $this->fail('Expected InsufficientCapacityException for overall capacity.');
        } catch (InsufficientCapacityException $e) {
            // expected
        }

        $this->assertSame(0, $a->fresh()->reserved_count);
        $this->assertSame(0, $b->fresh()->reserved_count);

        // 3 + 2 = 5 fits exactly.
        $this->service()->reserve($event, [$a->id => 3, $b->id => 2]);
        $this->assertSame(3, $a->fresh()->reserved_count);
        $this->assertSame(2, $b->fresh()->reserved_count);
    }

    public function test_reserve_counts_sold_against_overall_event_capacity(): void
    {
        $event = Event::factory()->create(['capacity' => 10]);
        // 8 already sold across the event via one type.
        $type = $this->ticketType($event, capacity: 100, sold: 8);

        // Requirement 5.6 — only 2 remain against the Event overall capacity.
        $this->expectException(InsufficientCapacityException::class);
        $this->service()->reserve($event, [$type->id => 3]);
    }

    public function test_unlimited_event_capacity_is_only_bounded_by_ticket_types(): void
    {
        // Requirement 5.2/5.6 — NULL overall capacity = unlimited at event level.
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 1_000);

        $this->service()->reserve($event, [$type->id => 999]);
        $this->assertSame(999, $type->fresh()->reserved_count);
    }

    // ---- Release (idempotent) -----------------------------------------------

    public function test_release_restores_reserved_capacity(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 100, reserved: 20);

        // Requirement 10.7 — releasing returns held units.
        $this->service()->release($event, [$type->id => 20]);

        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    public function test_reserve_then_release_returns_to_pre_reservation_value(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 50, reserved: 5);

        $this->service()->reserve($event, [$type->id => 10]);
        $this->assertSame(15, $type->fresh()->reserved_count);

        $this->service()->release($event, [$type->id => 10]);

        // Requirement 10.7 — back to the pre-reservation value (5).
        $this->assertSame(5, $type->fresh()->reserved_count);
    }

    public function test_release_is_idempotent_and_never_goes_negative(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 100, reserved: 10);

        // Requirement 10.7 — a double release (expiry racing cancel / retried
        // job) is safe: reserved_count clamps at zero, never negative.
        $this->service()->release($event, [$type->id => 10]);
        $this->service()->release($event, [$type->id => 10]);

        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    // ---- Validation ----------------------------------------------------------

    public function test_reserve_rejects_ticket_type_from_another_event(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $foreignType = TicketType::factory()->create(); // different event

        // A request may never reserve against a Ticket_Type outside the Event.
        $this->expectException(InvalidArgumentException::class);
        $this->service()->reserve($event, [$foreignType->id => 1]);
    }

    public function test_zero_quantities_are_a_no_op(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = $this->ticketType($event, capacity: 10, reserved: 3);

        $this->service()->reserve($event, [$type->id => 0]);

        $this->assertSame(3, $type->fresh()->reserved_count);
    }
}
