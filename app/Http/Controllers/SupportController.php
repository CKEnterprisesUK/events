<?php

namespace App\Http\Controllers;

use App\Http\Controllers\SuperAdmin\ImpersonationController;
use App\Models\Company;
use App\Models\SupportRequest;
use App\Support\HelpCentre;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The in-dashboard "Contact support" form: a Company_User raises a ticket with
 * the platform operator (Events by CK Enterprises UK / the Super_Admins) when
 * the Help & Knowledge portal has not resolved their issue.
 *
 * Like {@see ProfileController} this is open to every authenticated
 * Company_User (any role) and sits OUTSIDE the `dashboard.tenant` middleware,
 * so the tenant global scope is not active here. The raising user's own
 * Company is therefore resolved explicitly (from `$user->company`, or from the
 * impersonation session flag for a Super_Admin acting inside a Company) and set
 * on the row, rather than relying on the tenant auto-fill.
 *
 * The consent checkbox — "allow CK Enterprises to access my account to assist
 * with this request" — is persisted on the ticket (`access_consent` +
 * `access_consent_at`). It is a customer-granted, per-ticket authorisation for
 * the existing Super_Admin impersonation mechanism, so operators have an
 * explicit, timestamped record of when access was permitted.
 */
class SupportController extends Controller
{
    /**
     * Show the support-request form. A pre-selected category may be passed in
     * the query string (e.g. from a Help article's "still stuck?" link).
     */
    public function create(Request $request): View
    {
        return view('dashboard.support.create', [
            'categories' => SupportRequest::CATEGORY_LABELS,
            'selectedCategory' => $request->query('category'),
            'supportEmail' => HelpCentre::PLATFORM_SUPPORT_EMAIL,
        ]);
    }

    /**
     * Validate and persist a support request for the acting user's Company,
     * recording the CK Enterprises account-access consent when granted.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->actingUser($request);
        $company = $this->actingCompany($request);

        // A Super_Admin who is not impersonating any Company has no Company to
        // file the ticket against — steer them to raise it another way rather
        // than creating an orphan row.
        if ($company === null) {
            return redirect()
                ->route('dashboard.support.create')
                ->with('support_error', 'Jump into a company before raising a support request on its behalf.');
        }

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in(array_keys(SupportRequest::CATEGORY_LABELS))],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'access_consent' => ['nullable', 'accepted'],
        ]);

        $consented = $request->boolean('access_consent');

        SupportRequest::withoutGlobalScopes()->create([
            'company_id' => $company->getKey(),
            'user_id' => $user?->getKey(),
            'category' => $data['category'],
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => SupportRequest::STATUS_OPEN,
            'access_consent' => $consented,
            'access_consent_at' => $consented ? now() : null,
        ]);

        return redirect()
            ->route('dashboard.support.create')
            ->with('status', "Thanks — your request has been sent to the CK Enterprises support team. We'll be in touch by email.");
    }

    /**
     * The authenticated actor raising the ticket.
     */
    private function actingUser(Request $request): ?\App\Models\User
    {
        return $request->user();
    }

    /**
     * Resolve the Company the ticket belongs to. A Company_User uses their own
     * Company; a Super_Admin uses the Company they have jumped into (if any),
     * mirroring {@see DashboardController::index}.
     */
    private function actingCompany(Request $request): ?Company
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            $impersonatedId = $request->session()->get(ImpersonationController::SESSION_KEY);

            return $impersonatedId !== null ? Company::find($impersonatedId) : null;
        }

        return $user?->company;
    }
}
