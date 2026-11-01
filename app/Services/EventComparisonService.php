<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the data for comparing two (or more) Events side by side on the
 * reports surface: how sales built up in the lead-up to each Event, and how
 * sales are distributed across the hours of the day.
 *
 * Both series are computed from the SAME "confirmed order" definition the rest
 * of reporting uses ({@see EventReportService::CONFIRMED_STATUSES}) and money is
 * summed as `ticket_subtotal_minor` (gross sales), matching the dashboard's
 * sales-over-time chart and the "Gross sales" headline figure, so the
 * comparison agrees with every other sales surface.
 *
 * The lead-up series is keyed by "days before the Event start", NOT by calendar
 * date, so two Events happening on different dates line up on a common x-axis
 * ("28 days out", "27 days out", … "event day") and can be overlaid to compare
 * how each sold in the run-up. An Event with no start date is still comparable
 * on the time-of-day series but yields an empty lead-up series.
 *
 * All queries are tenant-scoped via the global `company_id` scope on
 * {@see Order}, so a comparison only ever spans the acting Company's Events.
 */
class EventComparisonService
{
    /**
     * How many days of lead-up to chart, counting back from the Event start.
     */
    public const LEAD_UP_DAYS = 28;

    /**
     * Build the comparison payload for the given Events.
     *
     * @param  Collection<int, Event>  $events  the Events to compare (1–3).
     * @return array{
     *     lead_up: list<array{event_id:int, event_name:string, points: list<array{label:string, days_out:int, daily_minor:int, cumulative_minor:int}>}>,
     *     time_of_day: list<array{event_id:int, event_name:string, points: list<array{label:string, hour:int, total_minor:int}>}>,
     *     totals: list<array{event_id:int, event_name:string, orders:int, gross_minor:int, starts_at:?string}>,
     * }
     */
    public function compare(Collection $events): array
    {
        $leadUp = [];
        $timeOfDay = [];
        $totals = [];

        foreach ($events as $event) {
            /** @var Collection<int, Order> $orders */
            $orders = $event->orders()
                ->whereIn('status', EventReportService::CONFIRMED_STATUSES)
                ->get(['id', 'created_at', 'ticket_subtotal_minor']);

            $leadUp[] = [
                'event_id' => (int) $event->getKey(),
                'event_name' => (string) $event->name,
                'points' => $this->leadUpPoints($orders, $event->starts_at),
            ];

            $timeOfDay[] = [
                'event_id' => (int) $event->getKey(),
                'event_name' => (string) $event->name,
                'points' => $this->timeOfDayPoints($orders),
            ];

            $totals[] = [
                'event_id' => (int) $event->getKey(),
                'event_name' => (string) $event->name,
                'orders' => $orders->count(),
                'gross_minor' => (int) $orders->sum('ticket_subtotal_minor'),
                'starts_at' => $event->starts_at?->toIso8601String(),
            ];
        }

        return [
            'lead_up' => $leadUp,
            'time_of_day' => $timeOfDay,
            'totals' => $totals,
        ];
    }

    /**
     * Daily and cumulative gross sales over the {@see LEAD_UP_DAYS}-day run-up
     * to the Event, keyed by "days out" so Events on different dates align. The
     * series runs oldest-first: "{LEAD_UP_DAYS} days out" → "event day" (0).
     * Orders placed before the window opens are rolled into the first day's
     * cumulative figure so the cumulative line still reflects all prior sales;
     * orders placed after the Event are ignored (the run-up has ended).
     *
     * Returns an empty list when the Event has no start date — there is no
     * reference point to measure "days out" from.
     *
     * @param  Collection<int, Order>  $orders  the Event's confirmed orders.
     * @return list<array{label:string, days_out:int, daily_minor:int, cumulative_minor:int}>
     */
    private function leadUpPoints(Collection $orders, ?Carbon $startsAt): array
    {
        if ($startsAt === null) {
            return [];
        }

        $eventDay = $startsAt->copy()->startOfDay();
        $windowStart = $eventDay->copy()->subDays(self::LEAD_UP_DAYS);

        // Bucket each confirmed order into its "days out" slot (0 = event day).
        // Sales before the window are accumulated into the window's first slot
        // so the cumulative line starts from the true running total.
        $dailyByDaysOut = array_fill(0, self::LEAD_UP_DAYS + 1, 0);
        $priorToWindowMinor = 0;

        foreach ($orders as $order) {
            if ($order->created_at === null) {
                continue;
            }

            $placedDay = $order->created_at->copy()->startOfDay();

            if ($placedDay->greaterThan($eventDay)) {
                continue; // placed after the event — not part of the run-up.
            }

            if ($placedDay->lessThan($windowStart)) {
                $priorToWindowMinor += (int) $order->ticket_subtotal_minor;

                continue;
            }

            $daysOut = (int) $placedDay->diffInDays($eventDay);
            $daysOut = min(self::LEAD_UP_DAYS, max(0, $daysOut));
            // Store indexed so index 0 is the furthest-out day for an oldest→
            // newest series.
            $slot = self::LEAD_UP_DAYS - $daysOut;
            $dailyByDaysOut[$slot] += (int) $order->ticket_subtotal_minor;
        }

        $points = [];
        $cumulative = $priorToWindowMinor;

        for ($slot = 0; $slot <= self::LEAD_UP_DAYS; $slot++) {
            $daysOut = self::LEAD_UP_DAYS - $slot;
            $daily = $dailyByDaysOut[$slot];
            $cumulative += $daily;

            $points[] = [
                'label' => $daysOut === 0 ? 'Event day' : $daysOut.'d out',
                'days_out' => $daysOut,
                'daily_minor' => $daily,
                'cumulative_minor' => $cumulative,
            ];
        }

        return $points;
    }

    /**
     * Gross sales grouped by the clock hour (0–23) the order was placed, every
     * hour zero-filled so the daily shape is comparable across Events. Lets two
     * Events be compared on "sales by time of day" — e.g. whether one sold more
     * in the evening than the other.
     *
     * @param  Collection<int, Order>  $orders  the Event's confirmed orders.
     * @return list<array{label:string, hour:int, total_minor:int}>
     */
    private function timeOfDayPoints(Collection $orders): array
    {
        $byHour = array_fill(0, 24, 0);

        foreach ($orders as $order) {
            if ($order->created_at === null) {
                continue;
            }

            $hour = (int) $order->created_at->format('G');
            $byHour[$hour] += (int) $order->ticket_subtotal_minor;
        }

        $points = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $points[] = [
                'label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT).':00',
                'hour' => $hour,
                'total_minor' => $byHour[$hour],
            ];
        }

        return $points;
    }
}
