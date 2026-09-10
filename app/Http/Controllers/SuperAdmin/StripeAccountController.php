<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Super-admin "Payments / Connected accounts" surface: which Companies have
 * completed Stripe Connect onboarding and can actually take card payments,
 * which are stuck part-way, and which have never started. This is the
 * operational view for the common "a client says they're not getting paid"
 * question — a Super_Admin can see at a glance whether that Company's connected
 * account exists and has charges enabled, without impersonating.
 *
 * Not tenant-scoped — a Super_Admin operates across every Company. Reads the
 * `stripe_account_id` / `stripe_charges_enabled` columns the Connect flow and
 * the `account.updated` webhook keep current; the readiness verdict reuses
 * {@see Company::canAcceptPayments()} so there is a single source of truth.
 */
class StripeAccountController extends Controller
{
    /**
     * List every Company grouped by its Stripe connection state:
     *   - ready:      has a connected account AND charges are enabled
     *   - incomplete: has a connected account but charges are NOT yet enabled
     *                 (onboarding started but not finished / under review)
     *   - none:       has never begun onboarding (no connected account)
     */
    public function index(): View
    {
        $companies = Company::query()->orderBy('name')->get();

        /** @var Collection<int, Company> $ready */
        $ready = $companies->filter(fn (Company $c): bool => $c->canAcceptPayments())->values();

        /** @var Collection<int, Company> $incomplete */
        $incomplete = $companies
            ->filter(fn (Company $c): bool => $c->stripe_account_id !== null && ! $c->canAcceptPayments())
            ->values();

        /** @var Collection<int, Company> $none */
        $none = $companies->filter(fn (Company $c): bool => $c->stripe_account_id === null)->values();

        return view('admin.payments.index', [
            'ready' => $ready,
            'incomplete' => $incomplete,
            'none' => $none,
            'totals' => [
                'all' => $companies->count(),
                'ready' => $ready->count(),
                'incomplete' => $incomplete->count(),
                'none' => $none->count(),
            ],
        ]);
    }
}
