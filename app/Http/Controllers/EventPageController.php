<?php

namespace App\Http\Controllers;

use App\Jobs\SendTicketEmailJob;
use App\Models\Event;
use App\Models\Order;
use App\Models\TicketType;
use App\Services\Branding\BrandingResolver;
use App\Services\FeeCalculationService;
use App\Services\QrService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        FeeCalculationService $fees,
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

        $company = $tenantContext->company();

        return view('events.show', [
            'company' => $company,
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'branding' => $branding->forEvent($event),
            // Fee context so the checkout summary can mirror the server's
            // Pass_On total (subtotal + booking fee) before the customer is
            // sent to Stripe. `feePercentHundredths` is the effective percent
            // expressed as an integer count of hundredths-of-a-percent so the
            // front end can reproduce the server's half-up integer rounding.
            'feeHandlingMode' => $company->fee_handling_mode,
            'feePercentHundredths' => $fees->percentToHundredths($fees->effectivePercent($company)),
        ]);
    }

    /**
     * Self-service "lost my tickets" resend for the public Event page.
     *
     * A Customer enters the email they booked with; every confirmed Order that
     * email holds for THIS Event has its ticket email re-queued through the same
     * {@see SendTicketEmailJob} the original fulfilment and the dashboard resend
     * use (so the QR is identical — a pure function of the Order_Reference).
     *
     * Privacy: the response NEVER reveals whether the email actually has any
     * order. Whether zero or several Orders matched, the Customer sees the same
     * neutral confirmation, so the form can't be used to probe who has booked.
     * The Event is scoped to the active Company by the global tenant scope
     * (foreign/unpublished Events 404).
     */
    public function resendTickets(
        string $companySlug,
        Event $event,
        Request $request,
        QrService $qr,
    ): RedirectResponse {
        abort_unless($event->isPublished(), 404);

        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
        ]);

        $email = strtolower(trim($validated['email']));

        // Re-queue every confirmed (paid or free-confirmed) Order this email
        // holds for this Event. Scoped to the active Company by the global
        // tenant scope; unconfirmed/expired/cancelled orders are skipped.
        $orders = Order::query()
            ->where('event_id', $event->getKey())
            ->whereRaw('LOWER(customer_email) = ?', [$email])
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
            ->get();

        foreach ($orders as $order) {
            SendTicketEmailJob::dispatch($order->getKey(), $qr->payloadFor($order));
        }

        // Always the same neutral message, regardless of what matched, so the
        // endpoint never discloses whether the email has any booking.
        return back()->with(
            'resend_status',
            'If that email address matches a booking for this event, we\'ll send the tickets to it shortly. '
            .'If nothing arrives, please check your spam or junk folder, then contact the organiser using the details below.'
        )->withFragment('support');
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
