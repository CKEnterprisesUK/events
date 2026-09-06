<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\FeeCalculationService;
use App\Services\RoleAuthorization;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owner-facing Stripe Connect dashboard: shows the Company's connection status,
 * starts Connect Standard onboarding, and handles the return from Stripe.
 * (Requirements 11.1, 11.2, 11.3, 11.5)
 *
 * Every action is gated on the Owner-only `ACTION_MANAGE_STRIPE` permission
 * (design → RoleAuthorization); a non-Owner Company_User is denied with an
 * authorisation error and nothing changes. All Stripe interaction goes through
 * {@see StripePaymentService}, which is the fake in tests.
 */
class StripeConnectController extends Controller
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly FeeCalculationService $fees,
    ) {}

    /**
     * Show the Stripe connection status for the Owner's Company. When no
     * account is connected the status is "not connected"; otherwise it reflects
     * whether charges are enabled. (Requirements 11.3, 11.5)
     */
    public function show(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_STRIPE);

        $company = $this->ownerCompany();

        return view('dashboard.stripe.status', [
            'company' => $company,
            'connected' => $company->stripe_account_id !== null,
            'chargesEnabled' => (bool) $company->stripe_charges_enabled,
            // How CK's platform fee is applied to this Company's paid sales, so
            // the Owner can see exactly what's deducted before payout. The
            // effective percent is the Company override or the global default;
            // the mode decides whether the Company absorbs it or passes it on
            // to the customer as a booking fee. (Requirements 12.1–12.4, 13.4, 13.5)
            'feePercent' => $this->fees->effectivePercent($company),
            'feeMode' => $company->fee_handling_mode,
        ]);
    }

    /**
     * Begin Connect Standard onboarding and redirect the Owner to Stripe. A
     * connected account is created (or the existing one reused) and its id is
     * stored on the Company so the return step can resolve it. (Requirements
     * 11.1, 11.2)
     */
    public function start(): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_STRIPE);

        $company = $this->ownerCompany();

        $link = $this->stripe->createOnboardingLink(
            existingAccountId: $company->stripe_account_id,
            returnUrl: route('dashboard.stripe.return'),
            refreshUrl: route('dashboard.stripe.start'),
        );

        // Persist the (possibly newly created) account id now so a refresh or
        // an interrupted onboarding resumes the same connected account.
        if ($company->stripe_account_id !== $link->accountId) {
            $company->stripe_account_id = $link->accountId;
            $company->save();
        }

        return redirect()->away($link->url);
    }

    /**
     * Handle the return from Stripe onboarding: read the connected account's
     * capabilities and persist whether charges are enabled. (Requirements 11.2,
     * 11.3)
     */
    public function return(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_STRIPE);

        $company = $this->ownerCompany();

        if ($company->stripe_account_id !== null) {
            $capabilities = $this->stripe->retrieveAccountCapabilities($company->stripe_account_id);

            $company->stripe_charges_enabled = $capabilities->chargesEnabled;
            $company->save();
        }

        return redirect()->route('dashboard.stripe.status');
    }

    /**
     * The Owner's own Company. Authenticated Company_Users are scoped to their
     * own Company; an Owner without a Company (should not happen for this
     * Owner-gated surface) yields a 404 rather than acting on nothing.
     */
    private function ownerCompany(): Company
    {
        $company = Auth::user()?->company;

        if (! $company instanceof Company) {
            throw new NotFoundHttpException;
        }

        return $company;
    }
}
