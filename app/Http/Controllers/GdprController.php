<?php

namespace App\Http\Controllers;

use App\Services\GdprService;
use App\Services\RoleAuthorization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Customer GDPR export / delete-anonymise and the public privacy policy page.
 * (Requirement 22)
 *
 * Two distinct surfaces live on this controller:
 *
 *  - The dashboard GDPR tools (export / anonymise). GDPR data-subject handling
 *    is a data-controller compliance responsibility, so it is gated on the
 *    Owner's `ACTION_MANAGE_SETTINGS` — the same Company-settings authority that
 *    governs branding/terms. Any non-Owner Company role (Admin, Accountant,
 *    Scanner) is denied with a 403 leaving data unchanged; Super_Admins pass via
 *    the `Gate::before` bypass. (Requirements 22.1, 22.2, 3.3, 3.7, Property 6)
 *    These run under the reserved `/dashboard` prefix where `dashboard.tenant`
 *    binds the authenticated user's own Company onto the TenantContext, so the
 *    global `company_id` scope constrains every Order/Ticket/consent query to
 *    that Company — a Company can only export or anonymise its own Customer
 *    data, never another Company's. (Requirements 22.5, 1.5, Property 1)
 *
 *  - The privacy policy page. Public, at the reserved `/privacy` prefix (no
 *    tenant, no auth), always renders. (Requirement 22.3)
 */
class GdprController extends Controller
{
    public function __construct(private GdprService $gdpr) {}

    /**
     * The dashboard GDPR tools landing form (enter a Customer email to export
     * or anonymise). Owner-gated. (Requirements 22.1, 22.2)
     */
    public function index(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        return view('dashboard.gdpr.index');
    }

    /**
     * Export the requesting Company's stored personal data for the Customer
     * identified by the submitted email, as a downloadable JSON document.
     * Owner-gated and tenant-scoped. (Requirements 22.1, 22.5)
     */
    public function export(Request $request): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        $data = $request->validate([
            'customer_email' => ['required', 'string', 'email', 'max:254'],
        ]);

        $export = $this->gdpr->export($data['customer_email']);

        return response()
            ->json($export, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ->header(
                'Content-Disposition',
                'attachment; filename="gdpr-export.json"',
            );
    }

    /**
     * Anonymise (delete) the requesting Company's personal data for the
     * Customer identified by the submitted email, retaining transactional
     * records. Owner-gated and tenant-scoped. (Requirements 22.2, 22.5)
     */
    public function anonymise(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);

        $data = $request->validate([
            'customer_email' => ['required', 'string', 'email', 'max:254'],
        ]);

        $count = $this->gdpr->anonymise($data['customer_email']);

        return redirect()
            ->route('dashboard.gdpr.index')
            ->with('status', "Anonymised personal data on {$count} order(s).");
    }

    /**
     * The public privacy policy page. No tenant, no auth — always renders.
     * (Requirement 22.3)
     */
    public function privacy(): View
    {
        return view('privacy');
    }
}
