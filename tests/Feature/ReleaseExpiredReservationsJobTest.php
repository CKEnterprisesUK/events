<?php

namespace Tests\Feature;

use App\Jobs\ReleaseExpiredReservationsJob;
use App\Models\Event;
use App\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature: event-ticketing-platform
 *
 * Covers task 8.2 — the scheduled ReleaseExpiredReservationsJob that sweeps
 * reservations whose 900-second window has elapsed and hands their held
 * capacity back to each Ticket_Type, idempotently. (Requirements 10.7, 10.12)
 *
 * The authoritative reservation source is the REAL `orders`/`tickets` schema
 * created in task 12.2 (their own migrations). This test seeds reserved Orders
 * with elapsed `reserved_until` windows and their `tickets` rows, then asserts:
 *
 *   - `test_handle_is_a_safe_no_op_when_there_are_no_expired_reservations`
 *     verifies the job touches nothing when there is nothing to sweep,
 *     matching the job's documented contract.
 *
 *   - the remaining tests seed expired holds so the real release-of-expired
 *     path — restore availability + mark expired + idempotency — is exercised
 *     against ticket_types carrying a `reserved_count` hold and a passed
 *     `reserved_until`.
 *
 * Runs against the real MySQL test DB so the FOR UPDATE locking in the sweep
 * behaves as in production.
 */
class ReleaseExpiredReservationsJobTest extends TestCase
{
    use RefreshDatabase;

    private function job(): ReleaseExpiredReservationsJob
    {
        return new ReleaseExpiredReservationsJob;
    }

    /**
     * Insert a `reserved` Order whose window has elapsed, holding `qty` units of
     * $type via `reserved_count` and `qty` ticket rows.
     */
    private function expiredReservation(Event $event, TicketType $type, int $qty): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $event->company_id,
            'event_id' => $event->id,
            'order_reference' => strtoupper(\Illuminate\Support\Str::random(12)),
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.test',
            'status' => 'reserved',
            'ticket_subtotal_minor' => 0,
            'booking_fee_minor' => 0,
            'application_fee_minor' => 0,
            'order_total_minor' => 0,
            'fee_handling_mode' => 'absorb',
            'reserved_until' => now()->subSeconds(1),
            'created_at' => now()->subSeconds(920),
            'updated_at' => now()->subSeconds(920),
        ]);

        for ($i = 0; $i < $qty; $i++) {
            DB::table('tickets')->insert([
                'company_id' => $event->company_id,
                'order_id' => $orderId,
                'ticket_type_id' => $type->id,
                'status' => 'valid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $type->increment('reserved_count', $qty);

        return $orderId;
    }

    // ---- No-op resilience when there is nothing to sweep --------------------

    public function test_handle_is_a_safe_no_op_when_there_are_no_expired_reservations(): void
    {
        // The orders/tickets source exists (provided by the test-support
        // migration) but holds no expired reservations — the sweep must touch
        // nothing.
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'reserved_count' => 20,
        ]);

        $this->job()->handle(app(\App\Services\CapacityReservationService::class));

        // With no expired reservations, held capacity is left untouched.
        $this->assertSame(20, $type->fresh()->reserved_count);
    }

    // ---- Release-of-expired restores availability (Req 10.7, 10.12) ---------

    public function test_expired_reservation_is_released_and_order_marked_expired(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        // 5 already sold, 8 currently held (pre-reservation reserved was 0).
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'sold_count' => 5,
            'reserved_count' => 0,
        ]);

        $orderId = $this->expiredReservation($event, $type, 8);
        $this->assertSame(8, $type->fresh()->reserved_count);

        $this->job()->handle(app(\App\Services\CapacityReservationService::class));

        // Requirement 10.7/10.12 — held capacity returns to its
        // pre-reservation value; sold is untouched.
        $this->assertSame(0, $type->fresh()->reserved_count);
        $this->assertSame(5, $type->fresh()->sold_count);

        // The swept Order is flipped out of `reserved`.
        $this->assertSame('expired', DB::table('orders')->where('id', $orderId)->value('status'));
    }

    public function test_only_expired_reservations_are_released(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'reserved_count' => 0,
        ]);

        // One expired hold (3) and one still-active hold (4, window in future).
        $this->expiredReservation($event, $type, 3);

        $activeOrderId = DB::table('orders')->insertGetId([
            'company_id' => $event->company_id,
            'event_id' => $event->id,
            'order_reference' => strtoupper(\Illuminate\Support\Str::random(12)),
            'customer_name' => 'Test Customer',
            'customer_email' => 'customer@example.test',
            'status' => 'reserved',
            'ticket_subtotal_minor' => 0,
            'booking_fee_minor' => 0,
            'application_fee_minor' => 0,
            'order_total_minor' => 0,
            'fee_handling_mode' => 'absorb',
            'reserved_until' => now()->addSeconds(600),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($i = 0; $i < 4; $i++) {
            DB::table('tickets')->insert([
                'company_id' => $event->company_id,
                'order_id' => $activeOrderId,
                'ticket_type_id' => $type->id,
                'status' => 'valid',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $type->increment('reserved_count', 4);
        $this->assertSame(7, $type->fresh()->reserved_count);

        $this->job()->handle(app(\App\Services\CapacityReservationService::class));

        // Only the expired 3 are released; the active 4 remain held.
        $this->assertSame(4, $type->fresh()->reserved_count);
        $this->assertSame('reserved', DB::table('orders')->where('id', $activeOrderId)->value('status'));
    }

    public function test_sweep_is_idempotent_across_repeated_runs(): void
    {
        $event = Event::factory()->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create([
            'capacity' => 100,
            'reserved_count' => 0,
        ]);

        $this->expiredReservation($event, $type, 6);

        // Requirement 10.7 — running the sweep twice releases the hold once;
        // reserved_count never goes negative and stays at the released value.
        $this->job()->handle(app(\App\Services\CapacityReservationService::class));
        $this->job()->handle(app(\App\Services\CapacityReservationService::class));

        $this->assertSame(0, $type->fresh()->reserved_count);
    }

    public function test_expired_holds_across_multiple_events_are_all_released(): void
    {
        $eventA = Event::factory()->unlimitedCapacity()->create();
        $typeA = TicketType::factory()->forEvent($eventA)->create([
            'capacity' => 50,
            'reserved_count' => 0,
        ]);

        $eventB = Event::factory()->unlimitedCapacity()->create();
        $typeB = TicketType::factory()->forEvent($eventB)->create([
            'capacity' => 50,
            'reserved_count' => 0,
        ]);

        $this->expiredReservation($eventA, $typeA, 5);
        $this->expiredReservation($eventB, $typeB, 9);

        $this->job()->handle(app(\App\Services\CapacityReservationService::class));

        // Each Event's expired holds are swept independently. (10.7)
        $this->assertSame(0, $typeA->fresh()->reserved_count);
        $this->assertSame(0, $typeB->fresh()->reserved_count);
    }
}
