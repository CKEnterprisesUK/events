<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\Events\CapacityComparison;
use App\Services\EventReadiness;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: event-management-and-reporting — Task 3.4
 *
 * Example-based unit coverage for {@see EventReadiness}: the fixed checklist
 * item set with its satisfied/blocking flags across varied Event states, and
 * the capacity comparison across the four <, >, =, and null cases.
 *
 * EventReadiness reads tenant-scoped relations (`ticketTypes()`) and
 * `Event::publishBlockers()`, so — mirroring tests/PBT/EventPublishGateTest.php
 * — each test establishes the Event's Company as the active tenant before
 * invoking the service, and the tenant is cleared in tearDown().
 *
 * Property-based coverage of these behaviours lives elsewhere; these are
 * concrete examples.
 *
 * Requirements: 2.1, 2.2, 2.3, 2.4, 3.2, 3.3.
 */
class EventReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Establish the Event's Company as the active tenant so tenant-scoped reads
     * (`ticketTypes()`) inside the service resolve against this Company's rows.
     */
    private function asTenantOf(Event $event): void
    {
        app(TenantContext::class)->setCompany($event->company);
    }

    private function service(): EventReadiness
    {
        return app(EventReadiness::class);
    }

    // ---- Checklist item set ---------------------------------------------------

    /**
     * Requirement 2.1 — the checklist exposes exactly the five items, in order.
     */
    public function test_checklist_item_keys_are_the_fixed_ordered_set(): void
    {
        $event = Event::factory()->create();
        $this->asTenantOf($event);

        $report = $this->service()->checklist($event);

        $keys = array_map(fn ($item) => $item->key, $report->items());

        $this->assertSame(
            ['name', 'starts_at', 'venue', 'ticket_types', 'shared_pool_capacity', 'capacity'],
            $keys,
        );
    }

    /**
     * Requirements 2.2, 2.3, 2.4 — start date and ticket type are blocking;
     * name, venue, and capacity sanity are advisory only.
     */
    public function test_blocking_flags_match_publish_prerequisites(): void
    {
        $event = Event::factory()->create();
        $this->asTenantOf($event);

        $report = $this->service()->checklist($event);

        $blocking = [];
        foreach ($report->items() as $item) {
            $blocking[$item->key] = $item->blocking;
        }

        $this->assertSame([
            'name' => false,
            'starts_at' => true,
            'venue' => false,
            'ticket_types' => true,
            'shared_pool_capacity' => true,
            'capacity' => false,
        ], $blocking);
    }

    // ---- Satisfied flags across event states ---------------------------------

    /**
     * Requirements 2.2, 2.3 — a fully configured Event (name, venue, start date,
     * one ticket type, capacity equal to the ticket-type sum) satisfies every
     * checklist item.
     */
    public function test_fully_set_event_satisfies_every_item(): void
    {
        $event = Event::factory()->create([
            'name' => 'Summer Gala',
            'venue' => 'Grand Hall',
            'starts_at' => now()->addWeek(),
            'capacity' => 100,
        ]);
        $this->asTenantOf($event);

        // One ticket type whose capacity equals the Event capacity => balanced.
        TicketType::factory()->forEvent($event)->create(['capacity' => 100]);

        $event->refresh();

        $report = $this->service()->checklist($event);

        foreach ($report->items() as $item) {
            $this->assertTrue(
                $item->satisfied,
                "Item '{$item->key}' should be satisfied for a fully-set event",
            );
        }
    }

    /**
     * Requirements 2.2, 2.4 — missing prerequisites leave the corresponding
     * items unsatisfied: null start date, no ticket types, and a blank venue.
     */
    public function test_missing_prerequisites_leave_items_unsatisfied(): void
    {
        // Null start date, blank venue, and (deliberately) no ticket types.
        $event = Event::factory()->create([
            'name' => 'Draft Event',
            'venue' => '   ',
            'starts_at' => null,
            'capacity' => null,
        ]);
        $this->asTenantOf($event);

        $event->refresh();

        $satisfied = [];
        foreach ($this->service()->checklist($event)->items() as $item) {
            $satisfied[$item->key] = $item->satisfied;
        }

        // Blocking prerequisites are unmet.
        $this->assertFalse($satisfied['starts_at'], 'null starts_at is not satisfied');
        $this->assertFalse($satisfied['ticket_types'], 'no ticket types is not satisfied');

        // Blank (whitespace-only) venue counts as unset.
        $this->assertFalse($satisfied['venue'], 'blank venue is not satisfied');

        // Name is present, capacity is null (unlimited => sane) => satisfied.
        $this->assertTrue($satisfied['name'], 'present name is satisfied');
        $this->assertTrue($satisfied['capacity'], 'unlimited capacity is sane');
    }

    // ---- Capacity comparison across <, >, =, null -----------------------------

    /**
     * Requirements 3.2, 3.3 — null overall capacity classifies as UNLIMITED.
     */
    public function test_capacity_null_is_unlimited(): void
    {
        $event = Event::factory()->create(['capacity' => null]);
        $this->asTenantOf($event);

        TicketType::factory()->forEvent($event)->create(['capacity' => 50]);

        $event->refresh();

        $this->assertSame(
            CapacityComparison::UNLIMITED,
            $this->service()->capacity($event)->state(),
        );
    }

    /**
     * Requirements 3.2, 3.3 — an overall capacity below the ticket-type sum
     * classifies as EVENT_BINDS.
     */
    public function test_capacity_below_types_sum_event_binds(): void
    {
        $event = Event::factory()->create(['capacity' => 30]);
        $this->asTenantOf($event);

        // Ticket-type sum = 20 + 30 = 50 > 30.
        TicketType::factory()->forEvent($event)->create(['capacity' => 20]);
        TicketType::factory()->forEvent($event)->create(['capacity' => 30]);

        $event->refresh();

        $this->assertSame(
            CapacityComparison::EVENT_BINDS,
            $this->service()->capacity($event)->state(),
        );
    }

    /**
     * Requirements 3.2, 3.3 — an overall capacity above the ticket-type sum
     * classifies as TYPES_BIND.
     */
    public function test_capacity_above_types_sum_types_bind(): void
    {
        $event = Event::factory()->create(['capacity' => 200]);
        $this->asTenantOf($event);

        // Ticket-type sum = 40 + 60 = 100 < 200.
        TicketType::factory()->forEvent($event)->create(['capacity' => 40]);
        TicketType::factory()->forEvent($event)->create(['capacity' => 60]);

        $event->refresh();

        $this->assertSame(
            CapacityComparison::TYPES_BIND,
            $this->service()->capacity($event)->state(),
        );
    }

    /**
     * Requirements 3.2, 3.3 — an overall capacity equal to the ticket-type sum
     * classifies as BALANCED.
     */
    public function test_capacity_equal_to_types_sum_is_balanced(): void
    {
        $event = Event::factory()->create(['capacity' => 75]);
        $this->asTenantOf($event);

        // Ticket-type sum = 25 + 50 = 75 == 75.
        TicketType::factory()->forEvent($event)->create(['capacity' => 25]);
        TicketType::factory()->forEvent($event)->create(['capacity' => 50]);

        $event->refresh();

        $this->assertSame(
            CapacityComparison::BALANCED,
            $this->service()->capacity($event)->state(),
        );
    }
}
