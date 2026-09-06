<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\Branding\BrandingResolver;
use App\Services\CapacityReservationService;
use App\Services\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/**
 * Handles the Customer's return from Stripe Checkout under the tenant group:
 * `/{company-slug}/{event-id}/checkout/{order}/success` and `.../cancel`.
 *
 * These pages are DISPLAY-ONLY and NEVER authoritative for paid state. The
 * browser redirect is treated as untrusted: a paid Order is only ever marked
 * `paid` by the idempotent `checkout.session.completed` webhook (task 16), so
 * the success page reflects a pending / awaiting-confirmation Order rather than
 * asserting payment itself. A free-only Order was already confirmed
 * (`free_confirmed`) by the checkout flow before the redirect, so its success
 * page shows a confirmed Order. (Requirements 10.12, 12.5, 12.6)
 *
 * The cancel page is the failed/cancelled-payment path (Requirement 10.12): it
 * releases the Order's reserved capacity so it is not stranded, marks a still
 * `reserved` Order `cancelled`, and surfaces a payment-not-completed message.
 * It is idempotent — a second visit finds the reservation already released and
 * changes nothing further.
 *
 * The Order is resolved by its Platform-unique Order_Reference within the
 * active Company (the global tenant scope) and the Event in the path; a
 * reference belonging to another Company or Event surfaces as 404.
 */
class StripeReturnController extends Controller
{
    public function __construct(private CapacityReservationService $capacity) {}

    /**
     * Success return page. Display-only: it reflects the Order's current state
     * (a paid Order awaits webhook confirmation; a free-only Order is already
     * confirmed) and never itself marks the Order paid. (Requirements 10.12,
     * 12.5, 12.6)
     */
    public function success(
        string $companySlug,
        Event $event,
        string $order,
        TenantContext $tenantContext,
        BrandingResolver $branding,
    ): View {
        abort_unless($event->isPublished(), 404);

        $orderModel = $this->resolveOrder($event, $order);

        return view('checkout.success', [
            'company' => $tenantContext->company(),
            'event' => $event,
            'order' => $orderModel,
            'branding' => $branding->forEvent($event),
            // A paid Order is only confirmed by the webhook; until then the
            // return page shows it as awaiting confirmation.
            'awaitingConfirmation' => $orderModel->status === Order::STATUS_RESERVED,
        ]);
    }

    /**
     * Cancel return page — the failed/cancelled-payment path. Releases the
     * reserved capacity, marks a still-reserved Order `cancelled`, and surfaces
     * a payment-not-completed message. Idempotent across repeat visits.
     * (Requirement 10.12)
     */
    public function cancel(
        string $companySlug,
        Event $event,
        string $order,
        TenantContext $tenantContext,
        BrandingResolver $branding,
    ): View {
        abort_unless($event->isPublished(), 404);

        $orderModel = $this->resolveOrder($event, $order);

        // Only a still-reserved Order needs unwinding; a paid/confirmed Order is
        // left untouched, and an already-cancelled/expired Order is a no-op.
        if ($orderModel->status === Order::STATUS_RESERVED) {
            DB::transaction(function () use ($event, $orderModel): void {
                $this->capacity->release($event, $this->reservedQuantities($orderModel));

                $orderModel->status = Order::STATUS_CANCELLED;
                $orderModel->save();
            });
        }

        return view('checkout.cancel', [
            'company' => $tenantContext->company(),
            'event' => $event,
            'order' => $orderModel,
            'branding' => $branding->forEvent($event),
        ]);
    }

    /**
     * Resolve the Order by its Order_Reference within the Event, under the
     * active Company scope. A foreign reference/Event surfaces as 404.
     */
    private function resolveOrder(Event $event, string $reference): Order
    {
        return Order::query()
            ->where('event_id', $event->id)
            ->where('order_reference', $reference)
            ->firstOrFail();
    }

    /**
     * The quantities held by the Order, as a Ticket_Type id => count map, to
     * hand back to the capacity service on cancel.
     *
     * @return array<int, int>
     */
    private function reservedQuantities(Order $order): array
    {
        return Ticket::query()
            ->where('order_id', $order->id)
            ->selectRaw('ticket_type_id, COUNT(*) as qty')
            ->groupBy('ticket_type_id')
            ->pluck('qty', 'ticket_type_id')
            ->map(fn ($qty): int => (int) $qty)
            ->all();
    }
}
