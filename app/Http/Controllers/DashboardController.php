<?php

namespace App\Http\Controllers;

use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\Onboarding\OnboardingChecklist;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    public function __construct(private readonly OnboardingChecklist $onboarding) {}

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
                'recentEvents' => collect(),
                'upcomingEvents' => collect(),
                'salesChart' => null,
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

        // Net to company (payout) = collected order total less the platform's
        // application fee — the direct-charge skim. Mirrors ReportController's
        // definition exactly so the dashboard and the reports page agree.
        $orderTotalMinor = (int) (clone $ordersQuery)->sum('order_total_minor');
        $applicationFeesMinor = (int) (clone $ordersQuery)->sum('application_fee_minor');
        $netToCompanyMinor = $orderTotalMinor - $applicationFeesMinor;

        // Tickets sold: valid tickets on the company's confirmed orders. Voided
        // tickets (from a later cancel/refund) do not count. Scoped explicitly
        // to the company; the tenant global scope is not active on this route.
        $ticketsSold = Ticket::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', Ticket::STATUS_VALID)
            ->whereExists(function ($query) use ($companyId): void {
                $query->select(DB::raw(1))
                    ->from('orders')
                    ->whereColumn('orders.id', 'tickets.order_id')
                    ->where('orders.company_id', $companyId)
                    ->whereIn('orders.status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED]);
            })
            ->count();

        // Recent events (most recently created) for the activity table.
        $recentEvents = (clone $eventsQuery)
            ->latest()
            ->limit(5)
            ->get();

        // Upcoming events: published-or-not events starting from now, ordered by
        // soonest first — the operationally urgent view an organiser wants.
        $upcomingEvents = (clone $eventsQuery)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->limit(5)
            ->get();

        $salesChart = $this->buildSalesChart($companyId);

        // Confirmed-order counts per event, scoped explicitly (the tenant
        // global scope is not active here, so we bypass it and filter by
        // company + event ids directly). Computed once for both tables.
        $eventIdsForCounts = $recentEvents->pluck('id')
            ->merge($upcomingEvents->pluck('id'))
            ->unique();

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

        $attachCounts = function (Event $event) use ($confirmedByEvent, $soldByEvent): void {
            $event->confirmed_orders_count = (int) ($confirmedByEvent[$event->id] ?? 0);

            $agg = $soldByEvent->get($event->id);
            $event->sell_through = $event->sellThrough(
                (int) ($agg->sold ?? 0),
                (int) ($agg->capped_capacity ?? 0),
            );
        };
        $recentEvents->each($attachCounts);
        $upcomingEvents->each($attachCounts);

        return view('dashboard', [
            'company' => $company,
            'stats' => [
                'total_events' => $totalEvents,
                'published_events' => $publishedEvents,
                'confirmed_orders' => $confirmedOrders,
                'gross_sales_minor' => $grossSalesMinor,
                'tickets_sold' => $ticketsSold,
                'net_to_company_minor' => $netToCompanyMinor,
            ],
            'recentEvents' => $recentEvents,
            'upcomingEvents' => $upcomingEvents,
            'salesChart' => $salesChart,
            'currency' => $company?->currency ?? 'GBP',
            'onboarding' => $onboarding,
        ]);
    }

    /**
     * Build a 30-day daily gross-sales series for the company plus a comparison
     * against the preceding 30-day window, so the dashboard can show a trend
     * line and an up/down delta.
     *
     * Gross sales = ticket subtotal (matching the "Gross sales" stat), summed
     * over confirmed orders by the day the order was created. Scoped explicitly
     * to the company; the tenant global scope is not active on this route.
     *
     * @return array{
     *     points: list<array{date: string, label: string, total_minor: int}>,
     *     current_total_minor: int,
     *     previous_total_minor: int,
     *     delta_pct: float|null,
     *     max_minor: int
     * }
     */
    private function buildSalesChart(int $companyId): array
    {
        $today = Carbon::today();
        $windowDays = 30;
        $currentStart = $today->copy()->subDays($windowDays - 1); // inclusive of today
        $previousStart = $currentStart->copy()->subDays($windowDays);

        // One grouped query across both windows (60 days), then bucket per day.
        $rows = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
            ->where('created_at', '>=', $previousStart->copy()->startOfDay())
            ->where('created_at', '<=', $today->copy()->endOfDay())
            ->selectRaw('DATE(created_at) as day, SUM(ticket_subtotal_minor) as total_minor')
            ->groupBy('day')
            ->pluck('total_minor', 'day');

        $totalsByDay = (new Collection($rows))->map(fn ($v) => (int) $v);

        // Current-window daily points (oldest → newest), zero-filled.
        $points = [];
        $currentTotal = 0;
        $maxMinor = 0;
        for ($i = 0; $i < $windowDays; $i++) {
            $date = $currentStart->copy()->addDays($i);
            $key = $date->toDateString();
            $value = (int) ($totalsByDay[$key] ?? 0);
            $currentTotal += $value;
            $maxMinor = max($maxMinor, $value);
            $points[] = [
                'date' => $key,
                'label' => $date->format('j M'),
                'total_minor' => $value,
            ];
        }

        // Previous-window total for the comparison delta.
        $previousTotal = 0;
        for ($i = 0; $i < $windowDays; $i++) {
            $key = $previousStart->copy()->addDays($i)->toDateString();
            $previousTotal += (int) ($totalsByDay[$key] ?? 0);
        }

        // Percentage change vs the prior window. Null when there's no prior
        // baseline to compare against (avoids a divide-by-zero / misleading
        // "+100%" when the company simply had no earlier sales).
        $deltaPct = $previousTotal > 0
            ? (($currentTotal - $previousTotal) / $previousTotal) * 100
            : null;

        return [
            'points' => $points,
            'current_total_minor' => $currentTotal,
            'previous_total_minor' => $previousTotal,
            'delta_pct' => $deltaPct,
            'max_minor' => $maxMinor,
        ];
    }
}
