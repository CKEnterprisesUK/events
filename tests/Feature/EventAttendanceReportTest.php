<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Reporting\AttendanceReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature: Attendance / check-in report.
 *
 * EventReportController::attendance renders the per-event attendance report at
 * GET dashboard/events/{event}/attendance from the Orders' scanned_at /
 * scanned_by stamps, and attendanceExport streams the same figures as CSV. Both
 * are gated on ACTION_VIEW_REPORTS and tenant-scoped (foreign Events 404).
 */
class EventAttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a confirmed paid order on `$event` with `$tickets` valid tickets,
     * optionally checked in (scanned) by `$scanner` at `$at`.
     */
    private function confirmedOrder(
        Event $event,
        TicketType $type,
        int $tickets = 1,
        ?User $scanner = null,
        ?string $at = null,
    ): Order {
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'order_total_minor' => 5_000,
            'fulfilled_at' => now(),
            'scanned_at' => $scanner ? ($at ?? now()) : null,
            'scanned_by' => $scanner?->id,
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($tickets)->create();

        return $order;
    }

    public function test_attendance_report_computes_check_in_and_no_show_figures(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $scanner = User::factory()->scanner()->create(['company_id' => $company->id]);

        $event = Event::factory()->for($company)->create(['capacity' => 100]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 100, 'price_minor' => 2_500]);

        // 3 confirmed orders (6 tickets); 2 orders (4 tickets) checked in.
        $this->confirmedOrder($event, $type, tickets: 2, scanner: $scanner);
        $this->confirmedOrder($event, $type, tickets: 2, scanner: $scanner);
        $this->confirmedOrder($event, $type, tickets: 2); // no-show

        $response = $this->actingAs($accountant)->get(route('dashboard.events.attendance', $event));

        $response->assertStatus(200);
        $response->assertViewIs('dashboard.events.attendance');

        /** @var AttendanceReport $report */
        $report = $response->viewData('attendance');
        $this->assertInstanceOf(AttendanceReport::class, $report);

        $this->assertSame(3, $report->confirmedOrders);
        $this->assertSame(2, $report->checkedInOrders);
        $this->assertSame(1, $report->noShowOrders());
        $this->assertEqualsWithDelta(66.7, $report->attendanceRate(), 0.05);
        $this->assertEqualsWithDelta(33.3, $report->noShowRate(), 0.05);

        // Headcount: 4 of 6 tickets arrived.
        $this->assertSame(6, $report->ticketsSold);
        $this->assertSame(4, $report->ticketsCheckedIn);
        $this->assertEqualsWithDelta(66.7, $report->headcountRate(), 0.05);
    }

    public function test_attendance_report_groups_check_ins_by_staff(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $alice = User::factory()->scanner()->create(['company_id' => $company->id, 'name' => 'Alice']);
        $bob = User::factory()->scanner()->create(['company_id' => $company->id, 'name' => 'Bob']);

        $event = Event::factory()->for($company)->create(['capacity' => 100]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 100]);

        $this->confirmedOrder($event, $type, tickets: 1, scanner: $alice);
        $this->confirmedOrder($event, $type, tickets: 1, scanner: $alice);
        $this->confirmedOrder($event, $type, tickets: 1, scanner: $bob);

        $report = $this->actingAs($admin)
            ->get(route('dashboard.events.attendance', $event))
            ->viewData('attendance');

        $names = array_column($report->byStaff, 'name');
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);

        // Ordered by orders scanned, descending — Alice (2) before Bob (1).
        $this->assertSame('Alice', $report->byStaff[0]['name']);
        $this->assertSame(2, $report->byStaff[0]['orders']);
    }

    public function test_attendance_report_builds_an_hourly_timeline(): void
    {
        $admin = User::factory()->admin()->create();
        $company = Company::find($admin->company_id);
        $scanner = User::factory()->scanner()->create(['company_id' => $company->id]);

        $event = Event::factory()->for($company)->create(['capacity' => 100]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 100]);

        $this->confirmedOrder($event, $type, scanner: $scanner, at: '2026-05-01 18:10:00');
        $this->confirmedOrder($event, $type, scanner: $scanner, at: '2026-05-01 18:45:00');
        $this->confirmedOrder($event, $type, scanner: $scanner, at: '2026-05-01 19:05:00');

        $report = $this->actingAs($admin)
            ->get(route('dashboard.events.attendance', $event))
            ->viewData('attendance');

        // Two buckets: the 18:00 hour (2 orders) and the 19:00 hour (1 order).
        $this->assertCount(2, $report->timeline);
        $this->assertSame('2026-05-01 18:00', $report->timeline[0]['hour']);
        $this->assertSame(2, $report->timeline[0]['orders']);
        $this->assertSame('2026-05-01 19:00', $report->timeline[1]['hour']);
        $this->assertSame(1, $report->timeline[1]['orders']);
    }

    public function test_attendance_export_streams_a_csv(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $scanner = User::factory()->scanner()->create(['company_id' => $company->id]);

        $event = Event::factory()->for($company)->create(['name' => 'Gala', 'capacity' => 100]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 100]);
        $this->confirmedOrder($event, $type, scanner: $scanner);

        $response = $this->actingAs($accountant)->get(route('dashboard.events.attendance.export', $event));

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString(
            'attachment; filename=attendance-gala-',
            (string) $response->headers->get('Content-Disposition'),
        );

        $body = $response->streamedContent();
        $this->assertStringContainsString('Confirmed orders', $body);
        $this->assertStringContainsString('Checked-in orders', $body);
    }

    public function test_box_office_and_scanner_cannot_view_the_attendance_report(): void
    {
        foreach ([
            User::factory()->boxOffice()->create(),
            User::factory()->scanner()->create(),
        ] as $user) {
            $company = Company::find($user->company_id);
            $event = Event::factory()->for($company)->create();

            $this->actingAs($user)
                ->get(route('dashboard.events.attendance', $event))
                ->assertForbidden();
        }
    }

    public function test_foreign_company_event_attendance_returns_404(): void
    {
        $accountant = User::factory()->accountant()->create();
        $foreignEvent = Event::factory()->create();

        $this->actingAs($accountant)
            ->get(route('dashboard.events.attendance', $foreignEvent))
            ->assertNotFound();
    }
}
