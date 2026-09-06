<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\TicketType;
use App\Services\Branding\BrandingResolver;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * Public Event page under `/{company-slug}/{event-id}/`.
 *
 * By the time an action runs, `ResolveTenant` has bound the active Company (or
 * already returned 404 for an unmatched or suspended slug), and the global
 * `company_id` scope constrains the Event lookup to that Company — so an
 * Event id belonging to another Company surfaces as 404. (Requirement 1.5)
 *
 * Publish gating (Requirements 5.4, 5.5):
 *   - A published Event's page is available and served to Customers. (5.4)
 *   - An unpublished Event is not viewable or purchasable: the page returns 404
 *     so Customers can neither view nor purchase tickets for it. (5.5)
 *
 * The page renders each Ticket_Type with its remaining availability
 * (`capacity - sold_count - reserved_count` — Requirement 6.10) and its
 * sale-window state (not yet on sale / on sale / sale ended — Requirements 6.4,
 * 6.5), under the Event's resolved branding (Event overrides else Company —
 * Requirements 7.1, 7.2, 7.5).
 */
class EventPageController extends Controller
{
    public function show(
        string $companySlug,
        Event $event,
        TenantContext $tenantContext,
        BrandingResolver $branding,
    ): View {
        // Unpublished Events block Customer view/purchase. (Requirement 5.5)
        abort_unless($event->isPublished(), 404);

        $now = Carbon::now();

        $ticketTypes = $event->ticketTypes()
            ->orderBy('id')
            ->get()
            ->map(fn (TicketType $type): array => [
                'id' => $type->getKey(),
                'name' => $type->name,
                'price_minor' => $type->price_minor,
                'is_free' => $type->isFree(),
                'available' => max(0, $type->availableQuantity()),
                'sold_out' => $type->availableQuantity() <= 0,
                'sale_state' => $this->saleState($type, $now),
                'on_sale' => $type->isOnSaleAt($now),
            ]);

        return view('events.show', [
            'company' => $tenantContext->company(),
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'branding' => $branding->forEvent($event),
        ]);
    }

    /**
     * Classify a Ticket_Type's sale window relative to `$now`, using the
     * half-open interval `[sale_starts_at, sale_ends_at)`:
     *   - `not_yet`  — before the window opens (Requirement 6.4);
     *   - `ended`    — at or after the window closes (Requirement 6.5);
     *   - `on_sale`  — within the window;
     *   - `unavailable` — no sale window is defined.
     */
    private function saleState(TicketType $type, Carbon $now): string
    {
        if ($type->sale_starts_at === null || $type->sale_ends_at === null) {
            return 'unavailable';
        }

        if ($now->lessThan($type->sale_starts_at)) {
            return 'not_yet';
        }

        if ($now->greaterThanOrEqualTo($type->sale_ends_at)) {
            return 'ended';
        }

        return 'on_sale';
    }
}
