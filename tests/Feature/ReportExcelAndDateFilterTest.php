<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\SimpleXlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Feature: Excel export for the company report + consistent from/to date
 * filtering on the per-event report and questions report.
 */
class ReportExcelAndDateFilterTest extends TestCase
{
    use RefreshDatabase;

    private function paidOrder(Event $event, TicketType $type, int $total, int $qty, int $daysAgo = 0): Order
    {
        $order = Order::factory()->forEvent($event)->create([
            'status' => Order::STATUS_PAID,
            'ticket_subtotal_minor' => $total,
            'application_fee_minor' => 0,
            'order_total_minor' => $total,
            'fulfilled_at' => now()->subDays($daysAgo),
            'created_at' => now()->subDays($daysAgo),
        ]);

        Ticket::factory()->forOrder($order)->forTicketType($type)->count($qty)->create();

        return $order;
    }

    // ---- Excel export -------------------------------------------------------

    public function test_simple_xlsx_writer_produces_a_valid_openable_workbook(): void
    {
        $bytes = (new SimpleXlsxWriter('Report'))
            ->addRow(['Metric', 'Value'])
            ->addRow(['Orders', 3])
            ->addRow(['Gross', 125.50])
            ->build();

        // It is a real zip archive (xlsx is a zip of OOXML parts).
        $this->assertStringStartsWith('PK', $bytes);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsxtest');
        file_put_contents($tmp, $bytes);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tmp) === true);
        // The core OOXML parts are present.
        $this->assertNotFalse($zip->locateName('[Content_Types].xml'));
        $this->assertNotFalse($zip->locateName('xl/workbook.xml'));
        $this->assertNotFalse($zip->locateName('xl/worksheets/sheet1.xml'));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        // The numeric cell is stored as a number, the label as inline text.
        $this->assertStringContainsString('<v>3</v>', $sheet);
        $this->assertStringContainsString('inlineStr', $sheet);
    }

    public function test_company_report_excel_export_downloads_an_xlsx(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->unlimitedCapacity()->create();
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000]);
        $this->paidOrder($event, $type, 5_000, 2);

        $response = $this->actingAs($accountant)->get(route('dashboard.reports.export-excel'));

        $response->assertStatus(200);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type'),
        );
        $this->assertStringContainsString('.xlsx', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', $response->getContent());
    }

    public function test_excel_export_is_gated_like_the_other_exports(): void
    {
        $scanner = User::factory()->scanner()->create();

        $this->actingAs($scanner)
            ->get(route('dashboard.reports.export-excel'))
            ->assertForbidden();
    }

    // ---- Per-event report date filtering ------------------------------------

    public function test_per_event_report_respects_the_date_range(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->create(['capacity' => 1000]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000, 'price_minor' => 2_500]);

        // One order today, one 40 days ago.
        $this->paidOrder($event, $type, 5_000, 2, daysAgo: 0);
        $this->paidOrder($event, $type, 5_000, 2, daysAgo: 40);

        // Unranged: both orders count.
        $all = $this->actingAs($accountant)
            ->get(route('dashboard.events.report', $event))
            ->viewData('report');
        $this->assertSame(2, $all->confirmedOrders);

        // Ranged to the last 7 days: only the recent order counts.
        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $ranged = $this->actingAs($accountant)
            ->get(route('dashboard.events.report', ['event' => $event, 'from' => $from, 'to' => $to]))
            ->viewData('report');
        $this->assertSame(1, $ranged->confirmedOrders);
        $this->assertSame(5_000, $ranged->grossRevenueMinor);
    }

    public function test_per_event_attendance_report_respects_the_date_range(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->create(['capacity' => 1000]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000]);

        $this->paidOrder($event, $type, 5_000, 1, daysAgo: 0);
        $this->paidOrder($event, $type, 5_000, 1, daysAgo: 40);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $ranged = $this->actingAs($accountant)
            ->get(route('dashboard.events.attendance', ['event' => $event, 'from' => $from, 'to' => $to]))
            ->viewData('attendance');

        $this->assertSame(1, $ranged->confirmedOrders);
    }

    public function test_questions_report_respects_the_date_range(): void
    {
        $accountant = User::factory()->accountant()->create();
        $company = Company::find($accountant->company_id);
        $event = Event::factory()->for($company)->create(['capacity' => 1000]);
        $type = TicketType::factory()->forEvent($event)->create(['capacity' => 1000]);
        $event->questions()->create([
            'company_id' => $company->id,
            'type' => EventQuestion::TYPE_FREE_TEXT,
            'label' => 'Dietary needs',
            'options' => null,
            'required' => false,
            'position' => 0,
        ]);

        $this->paidOrder($event, $type, 5_000, 1, daysAgo: 0);
        $this->paidOrder($event, $type, 5_000, 1, daysAgo: 40);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $count = $this->actingAs($accountant)
            ->get(route('dashboard.events.questions.report', ['event' => $event, 'from' => $from, 'to' => $to]))
            ->viewData('responseCount');

        $this->assertSame(1, $count);
    }
}
