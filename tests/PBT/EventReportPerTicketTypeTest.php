<?php

namespace Tests\PBT;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\EventReportService;
use App\Services\TenantContext;
use Eris\Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

/**
 * Property-based test for the per-Ticket_Type breakdown computed by
 * {@see EventReportService} (feature Property 9).
 *
 * Uses the Eris library (never hand-rolled generators) with a minimum of 100
 * iterations, per the design's Testing Strategy, running against the real MySQL
 * test database.
 *
 * Over generated Events carrying 1–3 Ticket_Types (each with distinct
 * price_minor / capacity / sold_count / reserved_count) and a mix of confirmed
 * and non-confirmed Orders whose valid and voided Tickets are spread across the
 * types, the property asserts that every `perTicketType` row from
 * `EventReportService::for($event)` reports exactly:
 *
 *   - sold          = count of `valid` Tickets of that ticket_type on CONFIRMED
 *                     Orders (status paid or free_confirmed).
 *   - remaining     = $type->capacity − $type->sold_count − $type->reserved_count.
 *   - revenue_minor = sold * $type->price_minor  (a Ticket carries no price, so
 *                     the service derives revenue from the type's price_minor).
 *
 * The expected figures are computed independently in the test from the
 * generated population, then compared against the service — rows are indexed by
 * type_id so the comparison is order-independent.
 *
 * The EventReportService queries Orders/Tickets, which are tenant-scoped, so
 * the Event's Company is established as the active tenant for each iteration
 * (mirroring EventReportAccountingTest) and cleared in tearDown.
 */
class EventReportPerTicketTypeTest extends PbtTestCase
{
    use RefreshDatabase;

    /**
     * The Order statuses an iteration may assign — the two confirmed states
     * plus a representative set of non-confirmed states that must be excluded.
     *
     * @var list<string>
     */
    private const STATUS_POOL = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
        Order::STATUS_RESERVED,
        Order::STATUS_REFUNDED,
        Order::STATUS_CANCELLED,
    ];

    /**
     * The confirmed statuses whose valid tickets count towards `sold`.
     *
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * Property 9: Per-ticket-type breakdown is consistent — for any generated
     * Event with 1–3 Ticket_Types and a population of confirmed/non-confirmed
     * Orders whose valid and voided Tickets are spread across those types, each
     * `perTicketType` row from EventReportService::for() reports sold as the
     * count of that type's valid tickets on confirmed orders, remaining as
     * capacity − sold_count − reserved_count, and revenue_minor as
     * sold * price_minor.
     *
     * Now also covers shared-pool types: each row carries its type's
     * `capacity_mode`, and a shared-pool type's `remaining` is null (it has no
     * per-type ceiling). (Requirement 2.6)
     *
     * **Validates: Requirements 6.3, 2.6**
     */
    // Feature: event-management-and-reporting, Property 9: Per-ticket-type breakdown is consistent
    public function test_per_ticket_type_breakdown_is_consistent(): void
    {
        // Minimum property-based iterations mandated by the Testing Strategy.
        $this->limitTo(self::MIN_ITERATIONS);

        $this->forAll(
            // 1–3 Ticket_Type seeds: [price_minor, capacity, sold_count, reserved_count].
            // sold_count + reserved_count stay well under capacity so remaining
            // spans positive values; distinct prices/capacities are natural.
            Generator\seq(Generator\tuple(
                Generator\choose(0, 50_000),   // price_minor (0 => free type)
                Generator\choose(10, 1_000),   // capacity
                Generator\choose(0, 5),        // sold_count
                Generator\choose(0, 5),        // reserved_count
            )),
            // A small sequence of Order seeds. Each: [statusIndex, typeIndex,
            // validCount, voidedCount]. The tickets attach to the type chosen
            // by typeIndex (mod the number of types).
            Generator\seq(Generator\tuple(
                Generator\choose(0, count(self::STATUS_POOL) - 1),
                Generator\choose(0, 2),        // which type (mod type count)
                Generator\choose(0, 4),        // valid tickets
                Generator\choose(0, 3),        // voided tickets (never counted)
            )),
        )
            ->then(function (array $typeSeeds, array $orderSeeds): void {
                // Keep per-iteration data small: 1–3 types, up to 6 orders.
                $typeSeeds = array_slice(array_values($typeSeeds), 0, 3);
                if ($typeSeeds === []) {
                    $typeSeeds = [[1_000, 100, 0, 0]];
                }
                $orderSeeds = array_slice(array_values($orderSeeds), 0, 6);

                // Build a single Company/Event and establish the Company as the
                // active tenant so the tenant-scoped Order/Ticket queries inside
                // EventReportService resolve against this Company's rows.
                $company = Company::factory()->create();
                $event = Event::factory()->for($company)->unlimitedCapacity()->create();

                app(TenantContext::class)->setCompany($company);

                // Create the Ticket_Types and remember each one's attributes so
                // the expected remaining/revenue can be computed independently.
                /** @var list<TicketType> $types */
                $types = [];
                /** @var array<int, array{price:int,capacity:int,sold_count:int,reserved:int,mode:string,remaining:?int}> $typeMeta */
                $typeMeta = [];

                foreach ($typeSeeds as $seed) {
                    [$price, $capacity, $soldCount, $reserved] = array_map('intval', $seed);

                    // Factory default is capped, so generated types keep the
                    // capped remaining semantics (capacity − sold − reserved).
                    $type = TicketType::factory()->forEvent($event)->create([
                        'price_minor' => $price,
                        'capacity' => $capacity,
                        'sold_count' => $soldCount,
                        'reserved_count' => $reserved,
                    ]);

                    $types[] = $type;
                    $typeMeta[$type->id] = [
                        'price' => $price,
                        'capacity' => $capacity,
                        'sold_count' => $soldCount,
                        'reserved' => $reserved,
                        'mode' => TicketType::MODE_CAPPED,
                        'remaining' => $capacity - $soldCount - $reserved,
                    ];
                }

                // Always include one shared-pool type so the remaining===null
                // branch is exercised. A shared-pool type has no per-type
                // ceiling, so its expected remaining is null. (Requirement 2.6)
                $sharedType = TicketType::factory()->forEvent($event)->sharedPool()->create([
                    'price_minor' => 1_500,
                ]);

                $types[] = $sharedType;
                $typeMeta[$sharedType->id] = [
                    'price' => (int) $sharedType->price_minor,
                    'capacity' => 0,
                    'sold_count' => 0,
                    'reserved' => 0,
                    'mode' => TicketType::MODE_SHARED_POOL,
                    'remaining' => null,
                ];

                // Expected count of valid tickets on confirmed orders, per type.
                $expectedSold = array_fill_keys(array_keys($typeMeta), 0);

                foreach ($orderSeeds as $seed) {
                    [$statusIndex, $typeIndex, $validCount, $voidedCount] = array_map('intval', $seed);

                    $status = self::STATUS_POOL[$statusIndex];
                    $type = $types[$typeIndex % count($types)];
                    $confirmed = in_array($status, self::CONFIRMED_STATUSES, true);

                    $order = Order::factory()->forEvent($event)->create([
                        'order_reference' => strtoupper(Str::random(12)),
                        'status' => $status,
                        'ticket_subtotal_minor' => 0,
                        'booking_fee_minor' => 0,
                        'application_fee_minor' => 0,
                        'order_total_minor' => 0,
                        'fulfilled_at' => now(),
                    ]);

                    if ($validCount > 0) {
                        Ticket::factory()->forOrder($order)->forTicketType($type)
                            ->count($validCount)->create();

                        // Only valid tickets on confirmed orders count towards sold.
                        if ($confirmed) {
                            $expectedSold[$type->id] += $validCount;
                        }
                    }

                    // Voided tickets never contribute, regardless of status.
                    if ($voidedCount > 0) {
                        Ticket::factory()->forOrder($order)->forTicketType($type)
                            ->voided()->count($voidedCount)->create();
                    }
                }

                $report = app(EventReportService::class)->for($event);

                // Index the service rows by type_id for order-independent compare.
                $rowsByType = [];
                foreach ($report->perTicketType as $row) {
                    $rowsByType[$row['type_id']] = $row;
                }

                $this->assertCount(
                    count($typeMeta),
                    $report->perTicketType,
                    'perTicketType must have exactly one row per ticket type.'
                );

                foreach ($typeMeta as $typeId => $meta) {
                    $this->assertArrayHasKey(
                        $typeId,
                        $rowsByType,
                        "perTicketType must include a row for ticket type {$typeId}."
                    );

                    $row = $rowsByType[$typeId];
                    $sold = $expectedSold[$typeId];

                    $this->assertSame(
                        $sold,
                        $row['sold'],
                        "sold for type {$typeId} must count valid tickets on confirmed orders."
                    );

                    // Every row carries its type's capacity_mode. (Requirement 6.3)
                    $this->assertArrayHasKey(
                        'capacity_mode',
                        $row,
                        "perTicketType row for type {$typeId} must carry a capacity_mode."
                    );
                    $this->assertSame(
                        $meta['mode'],
                        $row['capacity_mode'],
                        "capacity_mode for type {$typeId} must match the type's mode."
                    );

                    // A shared-pool type has no per-type ceiling, so remaining
                    // is null; a capped type reports capacity − sold − reserved.
                    // (Requirements 2.6, 6.3)
                    if ($meta['mode'] === TicketType::MODE_SHARED_POOL) {
                        $this->assertNull(
                            $row['remaining'],
                            "remaining for shared-pool type {$typeId} must be null."
                        );
                    } else {
                        $this->assertSame(
                            $meta['capacity'] - $meta['sold_count'] - $meta['reserved'],
                            $row['remaining'],
                            "remaining for type {$typeId} must be capacity − sold_count − reserved_count."
                        );
                    }

                    $this->assertSame(
                        $sold * $meta['price'],
                        $row['revenue_minor'],
                        "revenue_minor for type {$typeId} must be sold * price_minor."
                    );
                }
            });
    }
}
