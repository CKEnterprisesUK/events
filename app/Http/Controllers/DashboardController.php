<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Services\Onboarding\OnboardingChecklist;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

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
        $companyId = $user?->company_id;

        // No Company (Super_Admin): render the shell with empty figures.
        if ($companyId === null) {
            return view('dashboard', [
                'stats' => null,
                'recentEvents' => collect(),
                'currency' => 'GBP',
                'onboarding' => null,
            ]);
        }

        $company = $user->company;

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

        $recentEvents = (clone $eventsQuery)
            ->latest()
            ->limit(5)
            ->get();

        // Confirmed-order counts per recent event, scoped explicitly (the
        // tenant global scope is not active here, so we bypass it and filter by
        // company + event ids directly).
        $confirmedByEvent = Order::query()
            ->withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereIn('event_id', $recentEvents->pluck('id'))
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
            ->selectRaw('event_id, count(*) as aggregate')
            ->groupBy('event_id')
            ->pluck('aggregate', 'event_id');

        $recentEvents->each(function (Event $event) use ($confirmedByEvent): void {
            $event->confirmed_orders_count = (int) ($confirmedByEvent[$event->id] ?? 0);
        });

        return view('dashboard', [
            'stats' => [
                'total_events' => $totalEvents,
                'published_events' => $publishedEvents,
                'confirmed_orders' => $confirmedOrders,
                'gross_sales_minor' => $grossSalesMinor,
            ],
            'recentEvents' => $recentEvents,
            'currency' => $company?->currency ?? 'GBP',
            'onboarding' => $onboarding,
        ]);
    }
}
