<?php

namespace App\Http\Controllers;

use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Onboarding\OnboardingChecklist;
use App\Services\Reporting\TicketCounts;
use App\Services\Reporting\TicketMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The authenticated Company dashboard home.
 *
 * This route sits OUTSIDE the `dashboard.tenant` middleware (so no active
 * Company is bound onto the TenantContext), which means the global `company_id`
 * scope is not applied here. Every query below is therefore scoped *explicitly*
 * to the authenticated user's own `company_id` and bypasses the tenant global
 * scope, so a user only ever sees their own Company's figures. Super_Admins
 * (no `company_id`) get an empty overview and are steered to the admin surface.
 */
class DashboardController extends Controller
{
    /**
     * Selectable chart windows: key => [length, unit].
     *
     * @var array<string, array{0:int, 1:string, 2:string}>
     */
    public const CHART_RANGES = [
        '7d' => [7, 'day', '7 days'],
        '30d' => [30, 'day', '30 days'],
        '90d' => [90, 'day', '90 days'],
        '12m' => [12, 'month', '12 months'],
    ];

    /**
     * Hours before doors that the live dashboard / ops panel surfaces.
     */
    public const OPERATIONS_LEAD_HOURS = 24;

    public function __construct(
        private readonly OnboardingChecklist $onboarding,
        private readonly TicketMetrics $ticketMetrics,
    ) {}

    /**
     * Show an at-a-glance overview for the signed-in user's Company.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        // A Super_Admin who has jumped into a Company operates on that Company's
        // dashboard; otherwise a Company_User operates on their own Company.
        if ($user?->isSuperAdmin()) {
            $impersonatedId = $request->session()->get(ImpersonationController::SESSION_KEY);
            $company = $impersonatedId !== null ? Company::find($impersonatedId) : null;
        } else {
            $company = $user?->company;
        }

        $companyId = $company?->getKey();

        // No Company (Super_Admin not impersonating): render the shell with
        // empty figures and steer them to the platform surface.
        if ($companyId === null) {
            return view('dashboard', [
                'company' => null,
                'stats' => null,
                'upcomingEvents' => collect(),
                'operationalEvents' => collect(),
                'salesChart' => null,
                'chartRange' => '30d',
                'chartMetric' => 'revenue',
                'currency' => 'GBP',
                'onboarding' => null,
            ]);
        }

        // New-customer onboarding checklist. Only the Owner can complete the
        // Stripe/contacts/branding steps, so only the Owner sees it — and only
        // until every step is done, after which it disappears.
        $onboarding = $user->isOwner()
            ? $this->onboarding->for($company)
            : null;

        if ($onboarding !== null && $onboarding->isComplete()) {
            $onboarding = null;
        }

        // Events scoped explicitly to the user's own Company (the tenant global
        // scope is not active on this route — see the class docblock).
        $eventsQuery = Event::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId);

        $totalEvents = (clone $eventsQuery)->count();
        $publishedEvents = (clone $eventsQuery)->where('is_published', true)->count();

        // Confirmed orders (paid or free-confirmed) drive the sales figures.
        $ordersQuery = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED]);

        $confirmedOrders = (clone $ordersQuery)->count();
        $grossSalesMinor = (int) (clone $ordersQuery)->sum('ticket_subtotal_minor');

        // Net payout = collected order total less BOTH the platform's application
        // fee (our direct-charge skim) AND the actual Stripe card-processing fee
        // taken inside the connected account. Mirrors ReportController exactly so
        // the dashboard and the reports page agree. stripe_fee_minor is nullable
        // (not yet captured), so SUM treats a null as 0 and the figure fills in
        // as fees land/are backfilled. (Truthful-payout)
        $orderTotalMinor = (int) (clone $ordersQuery)->sum('order_total_minor');
        $applicationFeesMinor = (int) (clone $ordersQuery)->sum('application_fee_minor');
        $stripeFeesMinor = (int) (clone $ordersQuery)->sum('stripe_fee_minor');
        $netPayoutMinor = $orderTotalMinor - $applicationFeesMinor - $stripeFeesMinor;

        // Tickets sold: valid tickets on the company's confirmed orders, from
        // the shared TicketMetrics definitions so this KPI and the per-event
        // rows below can never disagree.
        $companyTickets = $this->ticketMetrics->forCompany($companyId);
        $ticketsSold = $companyTickets->sold;

        // Upcoming events: published-or-not events starting from now, ordered by
        // soonest first — the operationally urgent view an organiser wants.
        $upcomingEvents = (clone $eventsQuery)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        $chartRange = array_key_exists((string) $request->query('range'), self::CHART_RANGES)
            ? (string) $request->query('range')
            : '30d';
        $chartMetric = $request->query('metric') === 'tickets' ? 'tickets' : 'revenue';
        $salesChart = $this->buildSalesChart($companyId, $chartRange, $chartMetric);

        // Confirmed-order counts per event, scoped explicitly (the tenant
        // global scope is not active here, so we bypass it and filter by
        // company + event ids directly).
        $eventIdsForCounts = $upcomingEvents->pluck('id')->unique();

        $confirmedByEvent = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('event_id', $eventIdsForCounts)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
            ->selectRaw('event_id, count(*) as aggregate')
            ->groupBy('event_id')
            ->pluck('aggregate', 'event_id');

        // Sell-through aggregates per event, computed once for both tables to
        // avoid per-row queries. sold_count and capped per-type capacity are
        // summed from ticket_types, scoped explicitly to the company + event
        // ids (the tenant global scope is not active on this route).
        $soldByEvent = TicketType::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('event_id', $eventIdsForCounts)
            ->selectRaw('event_id, SUM(sold_count) as sold, SUM(CASE WHEN capacity_mode = ? THEN capacity ELSE 0 END) as capped_capacity', [TicketType::MODE_CAPPED])
            ->groupBy('event_id')
            ->get()
            ->keyBy('event_id');

        // Ticket counts per event from the SAME definitions as the KPI above
        // (valid tickets on confirmed orders) rather than the denormalised
        // ticket_types.sold_count counter, which can drift.
        $ticketsByEvent = $this->ticketMetrics->forEvents($companyId, $eventIdsForCounts->all());

        $attachCounts = function (Event $event) use ($confirmedByEvent, $soldByEvent, $ticketsByEvent): void {
            $event->confirmed_orders_count = (int) ($confirmedByEvent[$event->id] ?? 0);

            $agg = $soldByEvent->get($event->id);
            $counts = $ticketsByEvent[$event->id] ?? new TicketCounts;
            $event->ticket_counts = $counts;
            $event->sell_through = $event->sellThrough(
                $counts->sold,
                (int) ($agg->capped_capacity ?? 0),
            );
        };
        $upcomingEvents->each($attachCounts);

        // Events needing operational attention: starting within the lead window
        // (default 24h) or already under way (started in the last 12 hours).
        $operationalEvents = (clone $eventsQuery)
            ->where('is_published', true)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now()->subHours(12))
            ->where('starts_at', '<=', now()->addHours(self::OPERATIONS_LEAD_HOURS))
            ->orderBy('starts_at')
            ->get();
        $operationalCounts = $this->ticketMetrics->forEvents($companyId, $operationalEvents->pluck('id')->all());
        $operationalEvents->each(function (Event $event) use ($operationalCounts): void {
            $event->ticket_counts = $operationalCounts[$event->id] ?? new TicketCounts;
            $event->doors_open = $event->starts_at->lte(now());
        });

        return view('dashboard', [
            'company' => $company,
            'stats' => [
                'total_events' => $totalEvents,
                'published_events' => $publishedEvents,
                'confirmed_orders' => $confirmedOrders,
                'gross_sales_minor' => $grossSalesMinor,
                'tickets_sold' => $ticketsSold,
                'stripe_fees_minor' => $stripeFeesMinor,
                'net_payout_minor' => $netPayoutMinor,
            ],
            'upcomingEvents' => $upcomingEvents,
            'operationalEvents' => $operationalEvents,
            'salesChart' => $salesChart,
            'chartRange' => $chartRange,
            'chartMetric' => $chartMetric,
            'currency' => $company?->currency ?? 'GBP',
            'onboarding' => $onboarding,
        ]);
    }

    /**
     * Build the sales chart series for the selected window, plus the same-length
     * window immediately before it for comparison.
     *
     * Daily buckets for 7/30/90 days, monthly buckets for 12 months. Each point
     * carries BOTH revenue (ticket subtotal on confirmed orders) and tickets
     * (valid tickets on confirmed orders), bucketed by the order's created_at,
     * so tooltips can show exact figures whichever metric is plotted. The last
     * point is the current day/month and is flagged `partial` because it is
     * still in progress and not comparable with complete periods.
     *
     * @return array{
     *     range: string,
     *     metric: string,
     *     unit: string,
     *     range_label: string,
     *     points: list<array{label: string, full_label: string, previous_label: string, revenue_minor: int, tickets: int, previous_revenue_minor: int, previous_tickets: int, partial: bool}>,
     *     current_total: int,
     *     previous_total: int,
     *     current_revenue_minor: int,
     *     current_tickets: int,
     *     delta_pct: float|null,
     *     max_value: int
     * }
     */
    private function buildSalesChart(int $companyId, string $range, string $metric): array
    {
        [$length, $unit, $rangeLabel] = self::CHART_RANGES[$range];
        $monthly = $unit === 'month';

        $today = Carbon::today();
        $currentStart = $monthly
            ? $today->copy()->startOfMonth()->subMonths($length - 1)
            : $today->copy()->subDays($length - 1);
        $previousStart = $monthly
            ? $currentStart->copy()->subMonths($length)
            : $currentStart->copy()->subDays($length);

        $confirmed = [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED];

        $revenueRows = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', $confirmed)
            ->where('created_at', '>=', $previousStart->copy()->startOfDay())
            ->where('created_at', '<=', $today->copy()->endOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(ticket_subtotal_minor) as total_minor')
            ->groupBy('day')
            ->pluck('total_minor', 'day');

        $ticketRows = DB::table('tickets')
            ->join('orders', 'orders.id', '=', 'tickets.order_id')
            ->where('orders.company_id', $companyId)
            ->whereIn('orders.status', $confirmed)
            ->where('tickets.status', Ticket::STATUS_VALID)
            ->where('orders.created_at', '>=', $previousStart->copy()->startOfDay())
            ->where('orders.created_at', '<=', $today->copy()->endOfDay())
            ->selectRaw('DATE(orders.created_at) as day, COUNT(*) as qty')
            ->groupBy('day')
            ->pluck('qty', 'day');

        // Sum the daily rows into the bucket key used by the series.
        $bucketKey = fn (string $day): string => $monthly ? substr($day, 0, 7) : $day;
        $revenueByBucket = [];
        foreach ($revenueRows as $day => $value) {
            $key = $bucketKey((string) $day);
            $revenueByBucket[$key] = ($revenueByBucket[$key] ?? 0) + (int) $value;
        }
        $ticketsByBucket = [];
        foreach ($ticketRows as $day => $value) {
            $key = $bucketKey((string) $day);
            $ticketsByBucket[$key] = ($ticketsByBucket[$key] ?? 0) + (int) $value;
        }

        $keyFor = fn (Carbon $date): string => $monthly ? $date->format('Y-m') : $date->toDateString();
        $at = fn (Carbon $start, int $i): Carbon => $monthly ? $start->copy()->addMonths($i) : $start->copy()->addDays($i);

        $points = [];
        $currentRevenue = $currentTickets = $previousRevenue = $previousTickets = 0;
        $maxValue = 0;

        for ($i = 0; $i < $length; $i++) {
            $date = $at($currentStart, $i);
            $previousDate = $at($previousStart, $i);
            $key = $keyFor($date);
            $previousKey = $keyFor($previousDate);

            $revenue = (int) ($revenueByBucket[$key] ?? 0);
            $tickets = (int) ($ticketsByBucket[$key] ?? 0);
            $prevRevenue = (int) ($revenueByBucket[$previousKey] ?? 0);
            $prevTickets = (int) ($ticketsByBucket[$previousKey] ?? 0);

            $currentRevenue += $revenue;
            $currentTickets += $tickets;
            $previousRevenue += $prevRevenue;
            $previousTickets += $prevTickets;

            $maxValue = max(
                $maxValue,
                $metric === 'tickets' ? max($tickets, $prevTickets) : max($revenue, $prevRevenue),
            );

            $points[] = [
                'label' => $monthly ? $date->format('M') : $date->format('j M'),
                'full_label' => $monthly ? $date->format('F Y') : $date->format('D j M Y'),
                'previous_label' => $monthly ? $previousDate->format('F Y') : $previousDate->format('D j M Y'),
                'revenue_minor' => $revenue,
                'tickets' => $tickets,
                'previous_revenue_minor' => $prevRevenue,
                'previous_tickets' => $prevTickets,
                'partial' => $i === $length - 1,
            ];
        }

        $currentTotal = $metric === 'tickets' ? $currentTickets : $currentRevenue;
        $previousTotal = $metric === 'tickets' ? $previousTickets : $previousRevenue;

        // Null when there is no prior baseline (avoids a misleading "+100%").
        $deltaPct = $previousTotal > 0
            ? (($currentTotal - $previousTotal) / $previousTotal) * 100
            : null;

        return [
            'range' => $range,
            'metric' => $metric,
            'unit' => $unit,
            'range_label' => $rangeLabel,
            'points' => $points,
            'current_total' => $currentTotal,
            'previous_total' => $previousTotal,
            'current_revenue_minor' => $currentRevenue,
            'current_tickets' => $currentTickets,
            'delta_pct' => $deltaPct,
            'max_value' => $maxValue,
        ];
    }
}
