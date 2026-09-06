<?php

namespace App\Jobs;

use App\Models\Event;
use App\Services\CapacityReservationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Releases capacity held by reservations whose 900-second window has elapsed,
 * restoring each affected Ticket_Type's available capacity. (Requirements 10.7,
 * 10.12)
 *
 * ## Why this job exists
 *
 * {@see CapacityReservationService::reserve()} holds capacity by incrementing
 * `ticket_types.reserved_count` and stamps a `reserved_until` (now + 900s) on
 * the Order. If the Customer never completes payment (abandons checkout, closes
 * the tab) the hold would otherwise linger forever and slowly starve the Event
 * of availability. This scheduled job is the sweeper that finds those stale
 * holds and hands them back via {@see CapacityReservationService::release()},
 * so availability returns to the value it held immediately before the
 * reservation. (Requirements 10.7, 10.12)
 *
 * ## Scheduling
 *
 * Registered to run every minute in `routes/console.php`, in line with the
 * cron-drained-queue hosting model: the same per-minute `schedule:run` that
 * drains the DB queue also sweeps expired reservations, so no persistent worker
 * is required. Running frequently keeps the released-capacity latency close to
 * the 900s window. (Design → Jobs (DB Queue), Hosting and Deployment Notes)
 *
 * ## Idempotency
 *
 * The release path is idempotent end to end:
 *   - {@see CapacityReservationService::release()} clamps `reserved_count` at 0,
 *     so releasing the same hold twice never drives the count negative.
 *   - Each expired Order is flipped to `expired` inside the same transaction
 *     that reads it `FOR UPDATE`, so a second overlapping run (or a retried
 *     job) sees no `reserved` rows for it and does nothing.
 * Concurrent or repeated runs therefore converge to the same state. (10.7)
 *
 * ## Reserved_until / orders wiring
 *
 * The authoritative source of expired reservations is the `orders` table
 * (`status = 'reserved'` AND `reserved_until < now`), joined to its `tickets`
 * to recover the per-Ticket_Type quantities to release. Those tables are
 * created by task 12.2 (CheckoutController + orders/tickets migrations). The
 * {@see reservationSourceAvailable()} guard remains as a harmless safety net so
 * the sweep no-ops rather than errors if it ever runs before the schema exists
 * (e.g. a partial migration state); in normal operation the tables are present.
 */
class ReleaseExpiredReservationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Sweep every expired reservation and release its held capacity.
     *
     * Groups the expired holds by Event so each Event's Ticket_Types are
     * released in a single {@see CapacityReservationService::release()} call
     * (one locked transaction per Event), then marks the swept Orders expired.
     */
    public function handle(CapacityReservationService $capacity): void
    {
        // The orders/tickets tables are introduced in task 12.2. Until they
        // exist there is no reservation source, so the sweep is a safe no-op.
        // (Documented above: reserved_until/orders wiring completes in 12.x.)
        if (! $this->reservationSourceAvailable()) {
            return;
        }

        foreach ($this->expiredReservationsByEvent() as $eventId => $quantities) {
            $this->releaseEvent($capacity, (int) $eventId, $quantities);
        }
    }

    /**
     * Release one Event's expired holds and mark its expired Orders.
     *
     * Wrapped in a transaction that re-reads the expired Orders `FOR UPDATE`
     * so a concurrent/retried run cannot release the same hold twice: the
     * second run finds the Orders already flipped out of `reserved` and skips
     * them. Combined with the clamp-at-zero release, the whole operation is
     * idempotent. (Requirement 10.7)
     *
     * @param  array<int, int>  $quantities  Ticket_Type id => quantity to release.
     */
    protected function releaseEvent(CapacityReservationService $capacity, int $eventId, array $quantities): void
    {
        DB::transaction(function () use ($capacity, $eventId, $quantities): void {
            $orderIds = $this->lockExpiredOrderIdsForEvent($eventId);

            if ($orderIds === []) {
                // Another run already swept these between our read and the lock.
                return;
            }

            $event = Event::withoutGlobalScopes()->find($eventId);

            if ($event === null) {
                return;
            }

            $capacity->release($event, $quantities);

            // Flip the swept Orders to `expired` so their hold is not released
            // again and the Order lifecycle reflects the elapsed window. (10.7)
            DB::table('orders')
                ->whereIn('id', $orderIds)
                ->update(['status' => 'expired', 'updated_at' => now()]);
        });
    }

    /**
     * Expired holds aggregated as: Event id => (Ticket_Type id => quantity).
     *
     * An expired reservation is an Order still in `reserved` status whose
     * `reserved_until` window has elapsed. Its per-Ticket_Type quantities come
     * from that Order's `tickets` rows. Bypasses tenant scope: this sweeper
     * runs in the scheduler with no resolved Company and must see every
     * Company's stale holds. (Requirements 10.7, 10.12)
     *
     * @return array<int, array<int, int>>
     */
    protected function expiredReservationsByEvent(): array
    {
        $rows = DB::table('orders')
            ->join('tickets', 'tickets.order_id', '=', 'orders.id')
            ->where('orders.status', 'reserved')
            ->where('orders.reserved_until', '<', now())
            ->groupBy('orders.event_id', 'tickets.ticket_type_id')
            ->select(
                'orders.event_id',
                'tickets.ticket_type_id',
                DB::raw('COUNT(*) as qty'),
            )
            ->get();

        $byEvent = [];

        foreach ($rows as $row) {
            $byEvent[(int) $row->event_id][(int) $row->ticket_type_id] = (int) $row->qty;
        }

        return $byEvent;
    }

    /**
     * Re-read (and lock) the ids of the still-`reserved`, still-expired Orders
     * for an Event so the release/flip happens atomically against concurrent
     * sweeps. (Requirement 10.7 idempotency)
     *
     * @return array<int, int>
     */
    protected function lockExpiredOrderIdsForEvent(int $eventId): array
    {
        return DB::table('orders')
            ->where('event_id', $eventId)
            ->where('status', 'reserved')
            ->where('reserved_until', '<', now())
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether the reservation source tables exist yet. They are created in task
     * 12.2; before that the sweep no-ops. (See class docblock.)
     */
    protected function reservationSourceAvailable(): bool
    {
        return Schema::hasTable('orders') && Schema::hasTable('tickets');
    }
}
