<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Services\FeeCalculationService;
use App\Services\RoleAuthorization;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
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
        private readonly AuditLogger $audit,
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
        $isNewAccount = $company->stripe_account_id !== $link->accountId;

        if ($isNewAccount) {
            $company->stripe_account_id = $link->accountId;
            $company->save();
        }

        // Record only the first time an account is created/linked, not every
        // refresh of the onboarding link, to keep the trail meaningful.
        if ($isNewAccount) {
            $this->audit->record(
                action: AuditLog::STRIPE_ONBOARDING_STARTED,
                auditable: $company,
                summary: 'Started Stripe Connect onboarding',
            );
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

            $wasEnabled = (bool) $company->stripe_charges_enabled;

            $company->stripe_charges_enabled = $capabilities->chargesEnabled;
            $company->save();

            // Record only a genuine change in the charges-enabled capability —
            // this is the gate on whether the Company can take paid orders.
            if ($wasEnabled !== $capabilities->chargesEnabled) {
                $this->audit->record(
                    action: AuditLog::STRIPE_CHARGES_ENABLED_CHANGED,
                    auditable: $company,
                    summary: $capabilities->chargesEnabled
                        ? 'Stripe charges became enabled'
                        : 'Stripe charges became disabled',
                    context: ['charges_enabled' => $capabilities->chargesEnabled],
                );
            }
        }

        return redirect()->route('dashboard.stripe.status');
    }

    /**
     * Update who bears the platform fee for this Company: Absorb (taken from
     * the ticket price) or Pass_On (added onto the customer's total as a
     * booking fee). Owner-gated. This only affects FUTURE orders — existing
     * orders snapshot the mode at creation. (Requirements 13.1, 13.3, 13.8)
     */
    public function updateFeeMode(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_STRIPE);

        $data = $request->validate([
            'fee_handling_mode' => ['required', Rule::in(Company::FEE_MODES)],
        ]);

        $company = $this->ownerCompany();
        $previousMode = $company->fee_handling_mode;
        $company->setFeeHandlingMode($data['fee_handling_mode']);

        $this->audit->record(
            action: AuditLog::FEE_MODE_CHANGED,
            auditable: $company,
            summary: 'Changed fee handling from '.$previousMode.' to '.$data['fee_handling_mode'],
            context: [
                'from' => $previousMode,
                'to' => $data['fee_handling_mode'],
            ],
        );

        return redirect()
            ->route('dashboard.stripe.status')
            ->with('fee_status', 'Fee handling updated. This applies to new orders from now on.');
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
