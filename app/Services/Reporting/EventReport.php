<?php

declare(strict_types=1);

namespace App\Services\Reporting;

/**
 * The computed accounting figures for a single Event, shared by the inline
 * per-event stats summary (Requirement 5) and the dedicated per-event report
 * page (Requirement 6). Immutable — produced by the EventReportService, which
 * is the single source of truth for the platform's accounting definitions
 * (confirmed = paid + free_confirmed; net = order_total − application_fee;
 * tickets sold = valid tickets on confirmed orders).
 *
 * All money is expressed in integer minor-currency units.
 */
final class EventReport
{
    /**
     * @param  int  $confirmedOrders  count of the Event's confirmed orders.
     * @param  int  $ticketsSold  valid tickets on confirmed orders (Req 5.2, 6.2).
     * @param  int  $grossRevenueMinor  gross revenue in minor units (Req 5.1, 6.2).
     * @param  int  $netToCompanyMinor  net after the PLATFORM fee only, in minor units (Req 5.3, 6.6).
     * @param  int  $stripeFeesMinor  actual Stripe card-processing fees captured on confirmed orders, in minor units; 0 where not yet captured (Truthful-payout).
     * @param  int  $netPayoutMinor  TRUTHFUL net that reaches the bank: gross − platform fee − Stripe fee, in minor units (Truthful-payout).
     * @param  ?int  $capacity  the Event capacity ceiling; null => unlimited (Req 5.4).
     * @param  list<array{type_id:int,name:string,sold:int,remaining:?int,revenue_minor:int,capacity_mode:string}>  $perTicketType  per-ticket-type breakdown; remaining is null for shared-pool types (Req 6.3).
     * @param  array<string,int>  $ordersByStatus  order counts keyed by status (Req 6.4).
     * @param  list<array{day:string,tickets:int,revenue_minor:int}>  $salesByDay  sales-over-time trend (Req 6.5).
     */
    public function __construct(
        public readonly int $confirmedOrders,
        public readonly int $ticketsSold,
        public readonly int $grossRevenueMinor,
        public readonly int $netToCompanyMinor,
        public readonly int $stripeFeesMinor,
        public readonly int $netPayoutMinor,
        public readonly ?int $capacity,
        public readonly array $perTicketType,
        public readonly array $ordersByStatus,
        public readonly array $salesByDay,
    ) {}

    /**
     * The Platform's Application_Fee on this Event's confirmed orders, derived
     * from the shared net definition (gross − net-to-company) so it stays a
     * function of the single source of truth rather than a separately-summed
     * figure. (Req 6.6)
     */
    public function platformFeesMinor(): int
    {
        return $this->grossRevenueMinor - $this->netToCompanyMinor;
    }

    /**
     * Capacity_Utilisation: the string 'unlimited' when there is no fixed
     * ceiling (capacity null or 0), otherwise Tickets_Sold as a percentage of
     * the capacity ceiling, rounded to one decimal place. (Requirement 5.4)
     */
    public function utilisation(): float|string
    {
        if ($this->capacity === null || $this->capacity === 0) {
            return 'unlimited';
        }

        return round($this->ticketsSold / $this->capacity * 100, 1);
    }
}
