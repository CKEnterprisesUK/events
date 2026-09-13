<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\AuditLogger;
use App\Services\FeeCalculationService;
use App\Services\RoleAuthorization;
use App\Services\Stripe\StripeAccountCapabilities;
use App\Services\Stripe\StripePaymentService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Stripe Connect dashboard: shows the Company's connection status, starts
 * Connect Standard onboarding, and handles the return from Stripe.
 * (Requirements 11.1, 11.2, 11.3, 11.5)
 *
 * Authorisation is split between two permissions (design → RoleAuthorization):
 *   - The onboarding/status flow (`show`, `start`, `return`) is gated on
 *     `ACTION_SETUP_STRIPE`, held by the Owner AND the Admin, so an Admin can
 *     get the Company's payments connected.
 *   - Ongoing management (`updateFeeMode` — how the platform fee is handled) is
 *     gated on the Owner-only `ACTION_MANAGE_STRIPE`.
 * A Company_User without the relevant permission is denied with an
 * authorisation error and nothing changes. All Stripe interaction goes through
 * {@see StripePaymentService}, which is the fake in tests.
 */
class StripeConnectController extends Controller
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly FeeCalculationService $fees,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * Show the Stripe connection status for the acting Company. When no account
     * is connected the status is "not connected"; otherwise it reflects whether
     * charges are enabled. Readable by anyone who can set up Stripe (Owner or
     * Admin); `canManage` tells the view whether to also expose the Owner-only
     * fee-handling controls. (Requirements 11.3, 11.5)
     */
    public function show(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_SETUP_STRIPE);

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
            // Payouts enabled and details submitted round out the picture of how
            // far onboarding has got, so the view can distinguish "connected but
            // unfinished" from "connected and verified". (Requirements 11.3, 11.4)
            'payoutsEnabled' => (bool) $company->stripe_payouts_enabled,
            'detailsSubmitted' => (bool) $company->stripe_details_submitted,
            // Why Stripe has restricted the account (if it has) and what it still
            // needs — surfaced so the Company sees the compliance errors that
            // otherwise only appear inside the Stripe Dashboard.
            'disabledReason' => $company->stripe_disabled_reason,
            'requirements' => $this->normaliseRequirements($company->stripe_requirements),
            'feePercent' => $this->fees->effectivePercent($company),
            'feeMode' => $company->fee_handling_mode,
            // The configurable estimate of Stripe's OWN card-processing fee, so
            // the Owner sees the full cost picture — our platform fee AND Stripe's
            // cut — rather than being told Stripe's charge is simply "separate".
            // These are DB-configured (Super_Admin), never hardcoded; the exact
            // fee on each order is captured from the balance transaction and shown
            // in reports. (Configurable-estimate feature)
            'stripeFeePercent' => (float) PlatformSetting::current()->stripe_fee_percent,
            'stripeFeeFixedMinor' => (int) PlatformSetting::current()->stripe_fee_fixed_minor,
            // Whether the current user may change the fee handling (Owner-only).
            // An Admin can view/connect Stripe but must not see the fee controls.
            'canManage' => Gate::allows(RoleAuthorization::ACTION_MANAGE_STRIPE),
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
        Gate::authorize(RoleAuthorization::ACTION_SETUP_STRIPE);

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
        Gate::authorize(RoleAuthorization::ACTION_SETUP_STRIPE);

        $company = $this->ownerCompany();

        if ($company->stripe_account_id !== null) {
            $capabilities = $this->stripe->retrieveAccountCapabilities($company->stripe_account_id);

            $wasEnabled = (bool) $company->stripe_charges_enabled;

            // Persist the full onboarding/verification snapshot, not just the
            // charges flag: returning from Account Links does NOT mean onboarding
            // finished (Stripe fires the return URL as soon as the user exits),
            // so we capture what is still outstanding — the "needs a business
            // verification document" state the Company otherwise only sees inside
            // Stripe. (Requirements 11.2, 11.3, 11.4)
            $this->applyCapabilities($company, $capabilities);
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
     * Copy a connected account's capability/verification snapshot onto the
     * Company (without saving). Kept in one place so the onboarding-return path
     * and any other caller persist the same set of fields consistently: the
     * charges/payouts gates, whether onboarding details were submitted, why the
     * account is disabled (if it is), and the outstanding requirements the
     * dashboard lists back to the Company. (Requirements 11.3, 11.4)
     */
    private function applyCapabilities(Company $company, StripeAccountCapabilities $capabilities): void
    {
        $company->stripe_charges_enabled = $capabilities->chargesEnabled;
        $company->stripe_payouts_enabled = $capabilities->payoutsEnabled;
        $company->stripe_details_submitted = $capabilities->detailsSubmitted;
        $company->stripe_disabled_reason = $capabilities->disabledReason;
        $company->stripe_requirements = $capabilities->requirements();
    }

    /**
     * Coerce the stored `stripe_requirements` JSON into the shape the view
     * relies on, so a null (never-refreshed) or partial value renders cleanly
     * rather than erroring. Always returns the four expected keys.
     *
     * @param  array<string, mixed>|null  $requirements
     * @return array{
     *     currently_due: list<string>,
     *     past_due: list<string>,
     *     pending_verification: list<string>,
     *     errors: list<array{requirement: string, code: string, reason: string}>
     * }
     */
    private function normaliseRequirements(?array $requirements): array
    {
        $requirements ??= [];

        return [
            'currently_due' => array_values(array_filter((array) ($requirements['currently_due'] ?? []), 'is_string')),
            'past_due' => array_values(array_filter((array) ($requirements['past_due'] ?? []), 'is_string')),
            'pending_verification' => array_values(array_filter((array) ($requirements['pending_verification'] ?? []), 'is_string')),
            'errors' => array_values(array_filter((array) ($requirements['errors'] ?? []), 'is_array')),
        ];
    }

    /**
     * The Company currently being acted on.
     *
     * This is the tenant bound onto {@see TenantContext} by `dashboard.tenant`:
     * the authenticated Owner's own Company OR — for a Super_Admin who has
     * jumped into a tenant — the impersonated Company. Using the resolved tenant
     * (rather than `Auth::user()->company`) ensures an impersonating Super_Admin
     * views and changes the impersonated Company's Stripe connection, not their
     * own. Falls back to the user's own Company for safety; a request with no
     * acting Company (should not happen on this Owner-gated surface) yields a
     * 404 rather than acting on nothing.
     */
    private function ownerCompany(): Company
    {
        $company = $this->tenantContext->company() ?? Auth::user()?->company;

        if (! $company instanceof Company) {
            throw new NotFoundHttpException;
        }

        return $company;
    }
}
