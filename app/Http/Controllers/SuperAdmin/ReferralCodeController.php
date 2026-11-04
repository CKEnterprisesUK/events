<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ReferralCode;
use App\Services\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Super-admin management of referral codes. Only Super_Admins can create them;
 * the `/admin` route group is guarded by `super.admin`.
 */
class ReferralCodeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.referral-codes.index', [
            'codes' => ReferralCode::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => ReferralCode::normalise((string) $request->input('code', '')) ?: Str::upper(Str::random(8))]);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9_-]+$/', 'unique:referral_codes,code'],
            'label' => ['nullable', 'string', 'max:255'],
            'fee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
        ], [
            'code.regex' => 'Use letters, numbers, hyphens and underscores only.',
        ]);

        $code = ReferralCode::create($validated);

        $this->audit->record(
            action: AuditLog::REFERRAL_CODE_CREATED,
            summary: 'Created referral code "'.$code->code.'" ('.$code->fee_percent.'% fee)',
            context: ['code' => $code->code, 'fee_percent' => $code->fee_percent],
        );

        return redirect()->route('admin.referral-codes.index')
            ->with('status', __('Referral code :code created.', ['code' => $code->code]));
    }

    public function toggle(ReferralCode $referralCode): RedirectResponse
    {
        $referralCode->update(['is_active' => ! $referralCode->is_active]);

        return redirect()->route('admin.referral-codes.index')
            ->with('status', __(':code is now :state.', [
                'code' => $referralCode->code,
                'state' => $referralCode->is_active ? 'active' : 'disabled',
            ]));
    }

    public function destroy(ReferralCode $referralCode): RedirectResponse
    {
        $code = $referralCode->code;
        $referralCode->delete();

        $this->audit->record(
            action: AuditLog::REFERRAL_CODE_DELETED,
            summary: 'Deleted referral code "'.$code.'"',
            context: ['code' => $code],
        );

        return redirect()->route('admin.referral-codes.index')
            ->with('status', __('Referral code :code deleted.', ['code' => $code]));
    }
}
