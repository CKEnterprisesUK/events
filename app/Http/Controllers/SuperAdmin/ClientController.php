<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\SupportRequest;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Super-admin "Clients" surface: every Company on the Platform with its key
 * stats, and a per-Company drill-down. A client is a Company; this gives a
 * Super_Admin a business view of each tenant's activity (events, sales, fees,
 * team) without impersonating them.
 *
 * Not tenant-scoped — a Super_Admin operates across every Company. Company-owned
 * children (Event/Order/Ticket) carry the global `company_id` tenant scope,
 * which with no resolved Company would hide every row, so all reads here use
 * `withoutGlobalScopes()` and filter by `company_id` directly (Company defines
 * no child relationships). All money is in integer minor currency units.
 */
class ClientController extends Controller
{
    /**
     * Order statuses representing settled money — the basis for sales / fee
     * figures. Everything else earned the Platform no realised revenue.
     *
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    /**
     * List every Company with its headline stats: events, confirmed orders,
     * gross sales and platform fees earned. Stats are gathered with grouped
     * queries across all Companies and folded back onto each row.
     */
    public function index(): View
    {
        $companies = Company::query()->orderBy('name')->get();

        $eventCounts = Event::query()
            ->withoutGlobalScopes()
            ->selectRaw('company_id, count(*) as aggregate')
            ->groupBy('company_id')
            ->pluck('aggregate', 'company_id');

        $orderStats = Order::query()
            ->withoutGlobalScopes()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->selectRaw('company_id, count(*) as orders, sum(ticket_subtotal_minor) as gross, sum(application_fee_minor) as fees')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $companies->each(function (Company $company) use ($eventCounts, $orderStats): void {
            $row = $orderStats->get($company->id);
            $company->events_count = (int) ($eventCounts->get($company->id, 0));
            $company->confirmed_orders_count = (int) ($row->orders ?? 0);
            $company->gross_sales_minor = (int) ($row->gross ?? 0);
            $company->platform_fees_minor = (int) ($row->fees ?? 0);
        });

        return view('admin.clients.index', [
            'companies' => $companies,
        ]);
    }

    /**
     * A single Company's detail: profile, stats, and its recent events with
     * per-event confirmed-order counts.
     */
    public function show(Company $company): View
    {
        $companyId = $company->getKey();

        $confirmedOrders = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('status', self::CONFIRMED_STATUSES);

        $eventsQuery = Event::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId);

        $ticketsSold = Ticket::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', Ticket::STATUS_VALID)
            ->whereIn('order_id', (clone $confirmedOrders)->select('id'))
            ->count();

        $stats = [
            'total_events' => (clone $eventsQuery)->count(),
            'published_events' => (clone $eventsQuery)->where('is_published', true)->count(),
            'confirmed_orders' => (clone $confirmedOrders)->count(),
            'tickets_sold' => $ticketsSold,
            'gross_sales_minor' => (int) (clone $confirmedOrders)->sum('ticket_subtotal_minor'),
            'booking_fees_minor' => (int) (clone $confirmedOrders)->sum('booking_fee_minor'),
            'platform_fees_minor' => (int) (clone $confirmedOrders)->sum('application_fee_minor'),
            'order_total_minor' => (int) (clone $confirmedOrders)->sum('order_total_minor'),
            'team_members' => User::query()->where('company_id', $companyId)->count(),
        ];

        $recentEvents = (clone $eventsQuery)->latest()->limit(10)->get();

        $confirmedByEvent = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('event_id', $recentEvents->pluck('id'))
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->selectRaw('event_id, count(*) as aggregate')
            ->groupBy('event_id')
            ->pluck('aggregate', 'event_id');

        $recentEvents->each(function (Event $event) use ($confirmedByEvent): void {
            $event->confirmed_orders_count = (int) ($confirmedByEvent[$event->id] ?? 0);
        });

        // This Company's recent support tickets. Read across the tenant scope
        // (no Company is resolved on the admin surface) and filtered explicitly
        // by company_id, newest first.
        $recentSupportRequests = SupportRequest::query()
            ->withoutGlobalScopes()
            ->with('user')
            ->where('company_id', $companyId)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // The Company's users, for owner management: the current Owner and the
        // other (non-super-admin) users who are eligible to receive ownership.
        $companyUsers = User::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_super_admin', false)
            ->orderBy('name')
            ->get();

        $owner = $companyUsers->firstWhere('role', User::ROLE_OWNER);
        $transferCandidates = $companyUsers->reject(fn (User $u): bool => $u->isOwner())->values();

        return view('admin.clients.show', [
            'company' => $company,
            'stats' => $stats,
            'recentEvents' => $recentEvents,
            'recentSupportRequests' => $recentSupportRequests,
            'owner' => $owner,
            'transferCandidates' => $transferCandidates,
        ]);
    }
}
