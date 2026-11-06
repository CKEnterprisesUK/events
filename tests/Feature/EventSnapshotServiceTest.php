<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\EventReportService;
use App\Services\EventSnapshotService;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The live door snapshot: its aggregate-query headline figures must equal the
 * attendance report's model-based figures (they share one definition and were
 * split only for speed), and its short cache must share one build between
 * viewers without ever crossing tenants.
 */
class EventSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Event $event;

    private TicketType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->event = Event::factory()->published()->for($this->company)->create();
        $this->type = TicketType::factory()->forEvent($this->event)->create();

        app(TenantContext::class)->setCompany($this->company);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->clear();

        parent::tearDown();
    }

    /**
     * An Order on the test Event with `$valid` valid and `$voided` voided
     * tickets, optionally checked in by `$scannedBy` (null = scanned by nobody
     * known).
     */
    private function order(string $status, int $valid, bool $scanned = false, ?User $scannedBy = null, int $voided = 0): Order
    {
        $order = Order::factory()->forEvent($this->event)->create([
            'status' => $status,
            'scanned_at' => $scanned ? now() : null,
            'scanned_by' => $scannedBy?->id,
        ]);

        Ticket::factory()->count($valid)->forOrder($order)->forTicketType($this->type)->create();
        Ticket::factory()->count($voided)->forOrder($order)->forTicketType($this->type)->voided()->create();

        return $order;
    }

    private function scanner(string $name): User
    {
        return User::factory()->scanner()->for($this->company)->create(['name' => $name]);
    }

    // ---- Aggregate figures equal the attendance report ----------------------

    public function test_live_attendance_matches_the_attendance_report_on_mixed_data(): void
    {
        $ada = $this->scanner('Ada');
        $ben = $this->scanner('Ben');

        $this->order(Order::STATUS_PAID, valid: 3, scanned: true, scannedBy: $ada, voided: 1);
        $this->order(Order::STATUS_PAID, valid: 2, scanned: true, scannedBy: $ada);
        $this->order(Order::STATUS_FREE_CONFIRMED, valid: 1, scanned: true, scannedBy: $ben);
        $this->order(Order::STATUS_PAID, valid: 4);
        // Checked in with no recorded operator.
        $this->order(Order::STATUS_PAID, valid: 2, scanned: true);
        // Not confirmed, so none of these count even though one is stamped scanned.
        $this->order(Order::STATUS_RESERVED, valid: 5);
        $this->order(Order::STATUS_REFUNDED, valid: 2, scanned: true, scannedBy: $ben);

        // Another Company's identical activity must not leak in.
        $other = Company::factory()->create();
        $otherEvent = Event::factory()->published()->for($other)->create();
        $otherOrder = Order::factory()->forEvent($otherEvent)->create(['status' => Order::STATUS_PAID, 'scanned_at' => now()]);
        Ticket::factory()->count(9)->forOrder($otherOrder)->forTicketType(TicketType::factory()->forEvent($otherEvent)->create())->create();

        $reports = app(EventReportService::class);
        $report = $reports->attendanceFor($this->event);
        $live = $reports->liveAttendanceFor($this->event);

        $this->assertSame($report->confirmedOrders, $live['confirmedOrders']);
        $this->assertSame($report->checkedInOrders, $live['checkedInOrders']);
        $this->assertSame($report->ticketsSold, $live['ticketsSold']);
        $this->assertSame($report->ticketsCheckedIn, $live['ticketsCheckedIn']);
        $this->assertSame($report->byStaff, $live['byStaff']);

        // Guard against the comparison passing vacuously.
        $this->assertSame(12, $live['ticketsSold']);
        $this->assertSame(8, $live['ticketsCheckedIn']);
        $this->assertSame('Ada', $live['byStaff'][0]['name']);
    }

    public function test_live_attendance_is_empty_for_an_event_with_no_orders(): void
    {
        $live = app(EventReportService::class)->liveAttendanceFor($this->event);

        $this->assertSame(0, $live['confirmedOrders']);
        $this->assertSame(0, $live['ticketsSold']);
        $this->assertSame(0, $live['ticketsCheckedIn']);
        $this->assertSame([], $live['byStaff']);
    }

    // ---- Snapshot cache -----------------------------------------------------

    public function test_snapshot_is_shared_between_viewers_within_the_cache_window(): void
    {
        config(['api.snapshot_ttl' => 30]);
        $snapshots = app(EventSnapshotService::class);

        $this->order(Order::STATUS_PAID, valid: 2, scanned: true);
        $first = $snapshots->snapshot($this->event);

        $this->order(Order::STATUS_PAID, valid: 3, scanned: true);
        $second = $snapshots->snapshot($this->event);

        $this->assertSame(2, $first['stats']['scanned']);
        $this->assertSame($first, $second);
    }

    public function test_snapshot_is_rebuilt_every_time_when_the_cache_is_disabled(): void
    {
        config(['api.snapshot_ttl' => 0]);
        $snapshots = app(EventSnapshotService::class);

        $this->order(Order::STATUS_PAID, valid: 2, scanned: true);
        $this->assertSame(2, $snapshots->snapshot($this->event)['stats']['scanned']);

        $this->order(Order::STATUS_PAID, valid: 3, scanned: true);
        $this->assertSame(5, $snapshots->snapshot($this->event)['stats']['scanned']);
    }

    public function test_cached_snapshots_are_kept_separate_per_event(): void
    {
        config(['api.snapshot_ttl' => 30]);
        $snapshots = app(EventSnapshotService::class);

        $this->order(Order::STATUS_PAID, valid: 2, scanned: true);
        $snapshots->snapshot($this->event);

        $otherEvent = Event::factory()->published()->for($this->company)->create();

        $this->assertSame(0, $snapshots->snapshot($otherEvent)['stats']['scanned']);
    }
}
