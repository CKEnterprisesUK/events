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
    /**
     * Remaining-stock ratio (of a capped type's own capacity) at or below
     * which the storefront shows "Limited availability" rather than
     * "Available". 0.2 = the final 20% of the type's capacity.
     */
    private const LIMITED_THRESHOLD = 0.2;

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
            ->map(function (TicketType $type) use ($now): array {
                $available = max(0, $type->availableQuantity());

                return [
                    'id' => $type->getKey(),
                    'name' => $type->name,
                    'price_minor' => $type->price_minor,
                    'is_free' => $type->isFree(),
                    // Kept for the quantity stepper's `max` bound so the form
                    // can't request more than remain — never rendered as a count.
                    'available' => $available,
                    'sold_out' => $available <= 0,
                    'availability_status' => $this->availabilityStatus($type, $available),
                    'sale_state' => $this->saleState($type, $now),
                    'on_sale' => $type->isOnSaleAt($now),
                ];
            });

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

    /**
     * Coarse public availability signal derived from remaining stock, so the
     * storefront never exposes exact inventory counts to Customers:
     *   - `sold_out`  — nothing left;
     *   - `limited`   — at or below {@see self::LIMITED_THRESHOLD} of the
     *                   type's own capacity (capped types only);
     *   - `available` — otherwise, including shared-pool/unlimited types with
     *                   no finite per-type ceiling.
     */
    private function availabilityStatus(TicketType $type, int $available): string
    {
        if ($available <= 0) {
            return 'sold_out';
        }

        // Only capped types have a finite ceiling to compute a percentage
        // against; shared-pool / unlimited types are simply "available".
        if ($type->isCapped() && (int) $type->capacity > 0) {
            $ratio = $available / (int) $type->capacity;

            if ($ratio <= self::LIMITED_THRESHOLD) {
                return 'limited';
            }
        }

        return 'available';
    }
}
