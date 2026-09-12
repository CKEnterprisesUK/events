<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Services\AuditLogger;
use App\Services\FeeCalculationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin controller for Platform fee configuration. (Requirements 20.5,
 * 20.6)
 *
 * Two knobs, both consumed by {@see FeeCalculationService}:
 *   - the Global_Fee_Percent (`platform_settings.global_fee_percent`) applied
 *     to any Company that has no per-Company override, and
 *   - a per-Company override (`companies.company_fee_percent`) plus its
 *     Fee_Handling_Mode (`companies.fee_handling_mode`).
 *
 * Percentages are DECIMAL(5,2): 0.00–100.00 with two decimal places. Setting a
 * value here changes only how future Orders are priced — existing Orders
 * snapshot their money and fee mode at creation and are never mutated
 * (Requirement 13.8). This surface is not tenant-scoped; a Super_Admin sets the
 * fee for any Company.
 */
class FeeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Show the Global_Fee_Percent and every Company's override + fee mode.
     * (Requirements 20.5, 20.6)
     */
    public function index(): View
    {
        $setting = PlatformSetting::current();

        return view('admin.fees.index', [
            'globalFeePercent' => $setting->global_fee_percent,
            'stripeFeePercent' => $setting->stripe_fee_percent,
            'stripeFeeFixedMinor' => $setting->stripe_fee_fixed_minor,
            'companies' => Company::query()->orderBy('name')->get(),
        ]);
    }

    /**
     * Set the configurable estimate of Stripe's OWN card-processing fee — a
     * percent plus a fixed amount (in minor units) — used by the pre-purchase
     * calculator and the organiser's Stripe status page to show an approximate
     * Stripe cut before a payment settles. This is display-only guidance; the
     * exact fee on each order is captured from its balance transaction and drives
     * the realised net payout in reports. DB-configured so nothing about Stripe's
     * pricing is hardcoded, and easily corrected if Stripe changes its rates.
     * (Configurable-estimate feature)
     */
    public function updateStripeEstimate(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stripe_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            // Fixed part entered in major units (e.g. 0.20) for a natural admin
            // UX; stored as integer minor units.
            'stripe_fee_fixed' => ['required', 'numeric', 'min:0', 'max:10000'],
        ]);

        $fixedMinor = (int) round(((float) $validated['stripe_fee_fixed']) * 100);

        $setting = PlatformSetting::current();
        $previousPercent = $setting->stripe_fee_percent;
        $previousFixedMinor = $setting->stripe_fee_fixed_minor;

        $setting->stripe_fee_percent = $validated['stripe_fee_percent'];
        $setting->stripe_fee_fixed_minor = $fixedMinor;
        $setting->save();

        // Platform-level change (no tenant): recorded with a null company_id so
        // it appears only on the super-admin trail.
        $this->audit->record(
            action: AuditLog::STRIPE_FEE_ESTIMATE_CHANGED,
            summary: 'Changed the Stripe fee estimate to '.$validated['stripe_fee_percent'].'% + '.number_format($fixedMinor / 100, 2),
            context: [
                'percent_from' => $previousPercent,
                'percent_to' => $validated['stripe_fee_percent'],
                'fixed_minor_from' => $previousFixedMinor,
                'fixed_minor_to' => $fixedMinor,
            ],
        );

        return redirect()
            ->route('admin.fees.index')
            ->with('status', __('Stripe fee estimate updated.'));
    }

    /**
     * Set the Platform Global_Fee_Percent, applied to Companies without a
     * per-Company override. (Requirement 20.5)
     */
    public function updateGlobal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'global_fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $setting = PlatformSetting::current();
        $previous = $setting->global_fee_percent;
        $setting->global_fee_percent = $validated['global_fee_percent'];
        $setting->save();

        // Platform-level change (no tenant): recorded with a null company_id so
        // it appears only on the super-admin trail.
        $this->audit->record(
            action: AuditLog::FEE_GLOBAL_CHANGED,
            summary: 'Changed the global fee to '.$validated['global_fee_percent'].'%',
            context: [
                'from' => $previous,
                'to' => $validated['global_fee_percent'],
            ],
        );

        return redirect()
            ->route('admin.fees.index')
            ->with('status', __('Global fee percent updated.'));
    }

    /**
     * Set a per-Company Company_Fee_Percent override (or clear it back to the
     * global default when left blank) and the Company's Fee_Handling_Mode.
     * (Requirements 20.6, 13.1)
     */
    public function updateCompany(Request $request, Company $company): RedirectResponse
    {
        $validated = $request->validate([
            // Nullable: an empty override means "fall back to Global_Fee_Percent".
            'company_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fee_handling_mode' => ['required', Rule::in(Company::FEE_MODES)],
        ]);

        $previousPercent = $company->company_fee_percent;
        $previousMode = $company->fee_handling_mode;

        $company->company_fee_percent = $validated['company_fee_percent'] ?? null;
        $company->fee_handling_mode = $validated['fee_handling_mode'];
        $company->save();

        // Attributed to the target Company so it shows on that Company's trail
        // as well as the platform trail.
        $this->audit->record(
            action: AuditLog::FEE_COMPANY_CHANGED,
            auditable: $company,
            summary: 'Changed fees for '.$company->name,
            context: [
                'fee_percent_from' => $previousPercent,
                'fee_percent_to' => $validated['company_fee_percent'] ?? null,
                'fee_mode_from' => $previousMode,
                'fee_mode_to' => $validated['fee_handling_mode'],
            ],
        );

        return redirect()
            ->route('admin.fees.index')
            ->with('status', __('Fees updated for :name.', ['name' => $company->name]));
    }
}
