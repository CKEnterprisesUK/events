<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Services\RoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The native iOS scanner's Events surface — the app's landing tab. Lists the
 * Active_Company's Events with at-a-glance information (name, venue, start,
 * publish/cancel state), a tickets-sold figure, and the public web URL a staff
 * member can open to buy tickets or manage the event in the full web app.
 *
 * This is the surface the operator uses to CHOOSE which Event to scan for: the
 * selected Event's id is then sent to `POST /api/scan` (`event_id`) and
 * `GET /api/dashboard/live` (`?event=`) so scanning and the live dashboard are
 * scoped to that one Event rather than the server's guessed Active_Event.
 *
 * Gated on `ACTION_CHECK_IN` — the same authority as the scan/dashboard door
 * tools — so any role that can work the door (Owner, Admin, Box_Office,
 * Scanner) can see the events to scan for. A role without check-in (Accountant)
 * is denied 403 and no data is read. Every query runs under `api.tenant`, so
 * the global `company_id` scope confines results to the acting Company.
 */
class EventApiController extends Controller
{
    /**
     * List the Active_Company's Events, newest first, each with a confirmed
     * tickets-sold count and the public web URL. The client renders these on
     * the Events tab and uses the chosen event's id to scope scanning.
     *
     * The response also carries a `web_dashboard_url` so a Company with NO
     * events can link the operator to the web app to create their first event
     * (the "click here to start your event" empty state).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $events = Event::query()
            ->with('company')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $soldByEvent = $this->soldByEvent($events->pluck('id')->all());

        $rows = $events->map(function (Event $event) use ($soldByEvent): array {
            $slug = $event->company?->slug;

            return [
                'id' => (int) $event->getKey(),
                'name' => $event->name,
                'venue' => $event->venue,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'is_published' => (bool) $event->is_published,
                'is_cancelled' => $event->cancelled_at !== null,
                'capacity' => $event->capacity,
                'tickets_sold' => (int) ($soldByEvent[$event->id] ?? 0),
                // The public storefront Event page — where a staff member buys
                // tickets or shares the event. Null only if the Company somehow
                // has no slug (it always should on a real tenant).
                'web_url' => $slug !== null
                    ? route('event.page', ['companySlug' => $slug, 'event' => $event->id])
                    : null,
            ];
        })->values();

        return response()->json([
            'events' => $rows,
            // Where to send an operator with no events to get started. Points at
            // the web events dashboard; the app opens it in the browser.
            'web_dashboard_url' => url('/dashboard/events'),
        ]);
    }

    /**
     * Confirmed tickets-sold per event: the count of `valid` Tickets on
     * confirmed (paid / free_confirmed) Orders, keyed by event id. One grouped
     * query, no N+1. Tenant-scoped like every query on this surface.
     *
     * @param  list<int>  $eventIds
     * @return array<int, int>
     */
    private function soldByEvent(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $confirmedOrderIds = Order::query()
            ->whereIn('event_id', $eventIds)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED])
            ->pluck('id')
            ->all();

        if ($confirmedOrderIds === []) {
            return [];
        }

        return Ticket::query()
            ->join('orders', 'tickets.order_id', '=', 'orders.id')
            ->whereIn('tickets.order_id', $confirmedOrderIds)
            ->where('tickets.status', Ticket::STATUS_VALID)
            ->selectRaw('orders.event_id as event_id, COUNT(*) as sold')
            ->groupBy('orders.event_id')
            ->pluck('sold', 'event_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
