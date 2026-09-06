<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Event;
use App\Models\Order;
use App\Models\User;
use Illuminate\Contracts\View\View;

/**
 * Super-admin platform dashboard: an at-a-glance overview of the whole
 * Platform. Unlike the Company dashboard, this is deliberately NOT
 * tenant-scoped — a Super_Admin sees figures aggregated across every Company.
 *
 * The super-admin surface (`/admin`) resolves no active Company, and Orders /
 * Events carry the global `company_id` tenant scope (which, with no Company
 * resolved, would force `1 = 0` and hide every row). So every cross-Company
 * read here uses `withoutGlobalScopes()`. `Company` / `User` are not
 * tenant-scoped, so plain queries return all rows.
 *
 * All money is in integer minor currency units.
 */
class DashboardController extends Controller
{
    /**
     * Order statuses that represent settled money — a Customer paid, or a free
     * Order was confirmed. These drive the sales / fee figures. Reserved holds,
     * expired/cancelled/voided and refunded/disputed Orders earned the Platform
     * no realised revenue.
     *
     * @var list<string>
     */
    private const CONFIRMED_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_FREE_CONFIRMED,
    ];

    /**
     * Show platform-wide totals plus a snapshot of the most recently active
     * Companies.
     */
    public function index(): View
    {
        $confirmedOrders = Order::query()
            ->withoutGlobalScopes()
            ->whereIn('status', self::CONFIRMED_STATUSES);

        $companyStatuses = Company::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $stats = [
            'total_companies' => Company::query()->count(),
            'active_companies' => (int) ($companyStatuses[Company::STATUS_ACTIVE] ?? 0),
            'suspended_companies' => (int) ($companyStatuses[Company::STATUS_SUSPENDED] ?? 0),
            'total_users' => User::query()->count(),
            'total_events' => Event::query()->withoutGlobalScopes()->count(),
            'published_events' => Event::query()->withoutGlobalScopes()->where('is_published', true)->count(),
            'confirmed_orders' => (clone $confirmedOrders)->count(),
            'gross_sales_minor' => (int) (clone $confirmedOrders)->sum('ticket_subtotal_minor'),
            'platform_fees_minor' => (int) (clone $confirmedOrders)->sum('application_fee_minor'),
        ];

        // Newest Companies with their confirmed-order counts, so a Super_Admin
        // can see where recent activity is. Counts are gathered in one grouped
        // query then folded back onto the Companies.
        $recentCompanies = Company::query()->latest()->limit(8)->get();

        $ordersByCompany = Order::query()
            ->withoutGlobalScopes()
            ->whereIn('company_id', $recentCompanies->pluck('id'))
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->selectRaw('company_id, count(*) as orders, sum(ticket_subtotal_minor) as gross')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        $recentCompanies->each(function (Company $company) use ($ordersByCompany): void {
            $row = $ordersByCompany->get($company->id);
            $company->confirmed_orders_count = (int) ($row->orders ?? 0);
            $company->gross_sales_minor = (int) ($row->gross ?? 0);
        });

        return view('admin.dashboard.index', [
            'stats' => $stats,
            'recentCompanies' => $recentCompanies,
        ]);
    }
}
