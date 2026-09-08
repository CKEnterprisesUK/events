<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company-dashboard "Sharing" surface.
 *
 * Gives an organiser everything they need to promote their storefront and
 * events off-platform: a downloadable QR code that points at their public
 * storefront, and copy-and-paste embed snippets that drop an iframe onto their
 * own website — either a booking feed of ALL their published events, or a
 * per-event tickets widget. Every embedded link opens the relevant public
 * page (storefront / event / checkout) in a new tab.
 *
 * The page runs under the reserved `/dashboard` prefix inside the
 * `dashboard.tenant` group, so the acting user's own Company is bound onto the
 * {@see TenantContext} and the global `company_id` scope constrains every Event
 * query to that Company. Gated on `ACTION_MANAGE_EVENTS` to match the existing
 * per-event share / QR screens.
 */
class SharingController extends Controller
{
    /**
     * Render the Sharing page: the storefront share URL + QR download, a
     * global booking-feed embed snippet, and an accordion per upcoming
     * published Event with a per-event tickets embed snippet.
     */
    public function index(TenantContext $tenantContext): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        // Upcoming published Events (soonest first). The global company_id
        // scope is active on this route, so this already returns only the
        // acting Company's Events. Only published Events are shown because an
        // embed pointing at an unpublished Event would 404 for a visitor.
        $upcomingEvents = Event::query()
            ->where('is_published', true)
            ->whereNotNull('starts_at')
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get();

        return view('dashboard.sharing.index', [
            'company' => $company,
            'upcomingEvents' => $upcomingEvents,
            'storefrontUrl' => url('/'.$company->slug),
            'bookingEmbedUrl' => route('embed.booking', ['companySlug' => $company->slug]),
        ]);
    }

    /**
     * Stream a PNG QR code pointing at the Company's public storefront so the
     * organiser can print or share it. Mirrors {@see EventController::qr()}:
     * QR rendering needs the GD extension, and we surface a 500 rather than a
     * broken image if it is unavailable.
     */
    public function storefrontQr(TenantContext $tenantContext, QrService $qr): Response
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        $url = url('/'.$company->slug);

        try {
            $png = $qr->png($url, 512);
        } catch (\Throwable $e) {
            abort(500, 'QR code generation is unavailable on this server.');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="storefront-'.$company->slug.'-qr.png"',
        ]);
    }
}
