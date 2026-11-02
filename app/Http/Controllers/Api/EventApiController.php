<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Services\RoleAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * The native iOS scanner's Events surface — the app's landing tab. Lists the
 * Active_Company's Events with the information the app shows on an event's
 * detail page (name, venue/address, start time, a hero image, ticket types and
 * prices, and a tickets-sold figure) plus the public web URL a staff member can
 * open to buy tickets or manage the event in the full web app.
 *
 * This is the surface the operator uses to CHOOSE which Event to scan for: the
 * selected Event's id is then sent to `POST /api/scan` (`event_id`) and
 * `GET /api/dashboard/live` (`?event=`) so scanning and the live dashboard are
 * scoped to that one Event rather than the server's guessed Active_Event. Only
 * a `scannable` event (published and not cancelled) may be scanned — the flag
 * is surfaced here and re-enforced server-side on scan.
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
     * List the Active_Company's Events, newest first, each with the detail the
     * app renders and a confirmed tickets-sold count. The client uses the
     * chosen event's id to scope scanning, and `scannable` to decide whether
     * the "scan this event" action is offered.
     *
     * The response also carries a `web_dashboard_url` so a Company with NO
     * events can link the operator to the web app to create their first event
     * (the "click here to start your event" empty state).
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_CHECK_IN);

        $events = Event::query()
            ->with(['company', 'ticketTypes'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->get();

        $soldByEvent = $this->soldByEvent($events->pluck('id')->all());

        $rows = $events->map(fn (Event $event): array => $this->serialise($event, $soldByEvent))->values();

        return response()->json([
            'events' => $rows,
            // Where to send an operator with no events to get started. Points at
            // the web events dashboard; the app opens it in the browser.
            'web_dashboard_url' => url('/dashboard/events'),
        ]);
    }

    /**
     * The per-event payload the app's list and detail page render.
     *
     * @param  array<int, int>  $soldByEvent
     * @return array<string, mixed>
     */
    private function serialise(Event $event, array $soldByEvent): array
    {
        $slug = $event->company?->slug;
        $isCancelled = $event->cancelled_at !== null;
        $isPublished = (bool) $event->is_published;

        return [
            'id' => (int) $event->getKey(),
            'name' => $event->name,
            'description' => $event->description,
            'venue' => $event->venue,
            'address' => $event->address,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'is_published' => $isPublished,
            'is_cancelled' => $isCancelled,
            // A single flag the client trusts for "can this be scanned": an
            // event must be live (published) and not cancelled. Re-enforced on
            // the scan path server-side.
            'scannable' => $isPublished && ! $isCancelled,
            'capacity' => $event->capacity,
            'tickets_sold' => (int) ($soldByEvent[$event->id] ?? 0),
            'currency' => $event->company?->currency ?? 'GBP',
            // A hero image for the detail page: the event's own poster, falling
            // back to the company poster (mirrors the web listing).
            'image_url' => $this->imageUrl($event),
            'ticket_types' => $this->ticketTypes($event),
            // The public storefront Event page — where a staff member buys
            // tickets or shares the event. Null only if the Company somehow
            // has no slug (it always should on a real tenant).
            'web_url' => $slug !== null
                ? route('event.page', ['companySlug' => $slug, 'event' => $event->id])
                : null,
        ];
    }

    /**
     * The event's hero image URL: its own poster, else the company poster, else
     * null. Served from the public disk like the web surfaces.
     */
    private function imageUrl(Event $event): ?string
    {
        $path = $event->poster_path ?? $event->company?->poster_path;

        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * The event's ticket types with their prices (minor units) so the detail
     * page can list "what's on sale and how much". Display only.
     *
     * @return list<array{id:int, name:string, price_minor:int}>
     */
    private function ticketTypes(Event $event): array
    {
        return $event->ticketTypes
            ->sortBy('price_minor')
            ->map(fn (TicketType $type): array => [
                'id' => (int) $type->getKey(),
                'name' => $type->name,
                'price_minor' => (int) $type->price_minor,
            ])
            ->values()
            ->all();
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
