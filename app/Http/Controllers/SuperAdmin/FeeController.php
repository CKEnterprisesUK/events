<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PlatformSetting;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super-admin controller for Platform fee configuration. (Requirements 20.5,
 * 20.6)
 *
 * Two knobs, both consumed by {@see \App\Services\FeeCalculationService}:
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
    /**
     * Show the Global_Fee_Percent and every Company's override + fee mode.
     * (Requirements 20.5, 20.6)
     */
    public function index(): View
    {
        return view('admin.fees.index', [
            'globalFeePercent' => PlatformSetting::current()->global_fee_percent,
            'companies' => Company::query()->orderBy('name')->get(),
        ]);
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
        $setting->global_fee_percent = $validated['global_fee_percent'];
        $setting->save();

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

        $company->company_fee_percent = $validated['company_fee_percent'] ?? null;
        $company->fee_handling_mode = $validated['fee_handling_mode'];
        $company->save();

        return redirect()
            ->route('admin.fees.index')
            ->with('status', __('Fees updated for :name.', ['name' => $company->name]));
    }
}
