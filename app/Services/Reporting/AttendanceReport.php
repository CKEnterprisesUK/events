<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\EventReportService;

/**
 * The check-in / attendance figures for a single Event, derived from the
 * `scanned_at` / `scanned_by` stamps the door scanner writes on an Order.
 *
 * "Attendance" here is measured at the ORDER level — each confirmed Order is a
 * single QR that is scanned once (the scanner performs one atomic check-in per
 * Order), so an attended Order means its whole party arrived. A confirmed Order
 * with no `scanned_at` is a no-show. Cancelled/refunded/reserved orders are not
 * counted: only confirmed orders can be checked in.
 *
 * Immutable — produced by {@see EventReportService::attendanceFor()},
 * which is the single source of truth for these definitions so the attendance
 * report page and any export never diverge.
 */
final class AttendanceReport
{
    /**
     * @param  int  $confirmedOrders  confirmed orders eligible for check-in.
     * @param  int  $checkedInOrders  confirmed orders that have been scanned in.
     * @param  int  $ticketsSold  valid tickets on confirmed orders (the headcount denominator).
     * @param  int  $ticketsCheckedIn  valid tickets on the checked-in orders (the headcount arrived).
     * @param  ?string  $firstCheckInAt  ISO datetime of the first check-in, or null when none.
     * @param  ?string  $lastCheckInAt  ISO datetime of the most recent check-in, or null when none.
     * @param  list<array{name:string,orders:int,tickets:int}>  $byStaff  check-ins grouped by the staff member who scanned them.
     * @param  list<array{hour:string,orders:int,tickets:int}>  $timeline  check-ins grouped by clock hour, chronological.
     */
    public function __construct(
        public readonly int $confirmedOrders,
        public readonly int $checkedInOrders,
        public readonly int $ticketsSold,
        public readonly int $ticketsCheckedIn,
        public readonly ?string $firstCheckInAt,
        public readonly ?string $lastCheckInAt,
        public readonly array $byStaff,
        public readonly array $timeline,
    ) {}

    /**
     * Confirmed orders that were never scanned in — the no-shows.
     */
    public function noShowOrders(): int
    {
        return max(0, $this->confirmedOrders - $this->checkedInOrders);
    }

    /**
     * Order-level attendance rate as a percentage (checked-in ÷ confirmed),
     * rounded to one decimal place. Null when there are no confirmed orders, so
     * the view can render an em dash rather than a misleading 0%.
     */
    public function attendanceRate(): ?float
    {
        if ($this->confirmedOrders === 0) {
            return null;
        }

        return round($this->checkedInOrders / $this->confirmedOrders * 100, 1);
    }

    /**
     * No-show rate as a percentage (100 − attendance rate). Null when there are
     * no confirmed orders.
     */
    public function noShowRate(): ?float
    {
        $attendance = $this->attendanceRate();

        return $attendance === null ? null : round(100 - $attendance, 1);
    }

    /**
     * Headcount attendance rate as a percentage (tickets checked in ÷ tickets
     * sold), rounded to one decimal place. Null when no tickets were sold. This
     * complements the order-level rate: an order may cover several tickets, so
     * the headcount view answers "what fraction of expected people arrived".
     */
    public function headcountRate(): ?float
    {
        if ($this->ticketsSold === 0) {
            return null;
        }

        return round($this->ticketsCheckedIn / $this->ticketsSold * 100, 1);
    }
}
