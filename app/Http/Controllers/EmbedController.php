<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\StorefrontListing;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public embeddable widgets under `/{company-slug}/embed/...`.
 *
 * These endpoints render minimal, standalone HTML pages designed to be dropped
 * into a third-party website via an <iframe>. Two flavours exist:
 *
 *   - booking: a feed of ALL the Company's published Events. Each entry links
 *     to that Event's public page (or the storefront) so a visitor can browse
 *     and buy — opened in a new tab so they leave the host site's iframe.
 *   - tickets: a single Event's on-sale ticket types with a "Get tickets"
 *     action that jumps straight to that Event's public page / checkout, again
 *     in a new tab.
 *
 * They live inside the public `tenant` middleware group, so `ResolveTenant`
 * binds the active Company from the leading slug segment (404 on unmatched or
 * suspended) and the global `company_id` scope constrains every Event query.
 * Only PUBLISHED Events are ever exposed, matching the storefront. Responses
 * are returned with headers that permit framing, since the whole point is to
 * be embedded on the Company's own marketing site.
 */
class EmbedController extends Controller
{
    /**
     * A booking feed of every published Event for the resolved Company. Suitable
     * for a "what's on" strip embedded on the Company's own website; each Event
     * links out to its public page in a new tab.
     */
    public function booking(
        TenantContext $tenantContext,
        StorefrontListing $listing,
    ): View|Response {
        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        $response = response()->view('embed.booking', [
            'company' => $company,
            // Reuse the cached, published-only storefront listing so the embed
            // and the storefront always agree on what's live.
            'events' => $listing->forCompany($company),
            'storefrontUrl' => url('/'.$company->slug),
        ]);

        return $this->framable($response);
    }

    /**
     * A per-Event tickets widget: the Event's on-sale ticket types with a
     * "Get tickets" action that opens the Event's public page (which drives the
     * checkout) in a new tab. 404s an unpublished Event so a stale embed never
     * exposes a draft.
     */
    public function tickets(
        string $companySlug,
        Event $event,
        TenantContext $tenantContext,
    ): View|Response {
        $company = $tenantContext->company();
        abort_unless($company !== null, 404);

        // The global tenant scope binds $event to the active Company (foreign
        // ids 404); refuse to render an unpublished Event publicly.
        abort_unless($event->is_published, 404);

        // On-sale, purchasable ticket types, cheapest first — the same subset a
        // buyer would see on the public page. Loaded through the tenant scope.
        $now = now();
        $ticketTypes = $event->ticketTypes()
            ->orderBy('price_minor')
            ->orderBy('id')
            ->get()
            ->filter(fn ($type) => $type->isPurchasableAt($now))
            ->values();

        $response = response()->view('embed.tickets', [
            'company' => $company,
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'eventUrl' => route('event.page', [
                'companySlug' => $company->slug,
                'event' => $event->getKey(),
            ]),
        ]);

        return $this->framable($response);
    }

    /**
     * Relax framing headers so the page can be embedded cross-origin on the
     * Company's own site. We intentionally do NOT set X-Frame-Options and set a
     * permissive frame-ancestors so any host page may embed the widget; these
     * endpoints expose only already-public storefront data.
     */
    private function framable(Response $response): Response
    {
        $response->headers->remove('X-Frame-Options');
        $response->headers->set('Content-Security-Policy', 'frame-ancestors *');

        return $response;
    }
}
