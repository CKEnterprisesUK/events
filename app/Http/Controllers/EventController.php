<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\RoleAuthorization;
use App\Services\StorefrontListing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Company-dashboard controller for managing Events.
 *
 * Every write action (create/update/publish/unpublish) is gated by the
 * `ACTION_MANAGE_EVENTS` authorisation Gate, which the role matrix grants to
 * the Admin role (and, via the `Gate::before` bypass, Super_Admins). Anything
 * else is denied with a 403 leaving data unchanged. (Requirement 3.4, 3.7)
 *
 * Events are Company-owned: the dashboard runs under the reserved `/dashboard`
 * prefix where the URL carries no slug, so the `dashboard.tenant` middleware
 * binds the authenticated user's own Company onto the TenantContext. That makes
 * the global `company_id` scope constrain every Event query to the user's
 * Company — so an Admin only ever creates, reads, updates, or publishes Events
 * for their own Company, and a request for another Company's Event surfaces as
 * 404 with no modification. (Requirements 5.1, 5.3, 5.4)
 */
class EventController extends Controller
{
    public function __construct(private StorefrontListing $storefrontListing) {}

    /**
     * List the authenticated Company's Events. (Requirement 5.1)
     */
    public function index(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $events = Event::query()->latest()->get();

        return view('dashboard.events.index', ['events' => $events]);
    }

    /**
     * Create an Event scoped to the Admin's Company. (Requirement 5.1, 5.2)
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $this->validated($request);

        // company_id is auto-filled from the resolved tenant by the
        // BelongsToCompany trait; publish state defaults to unpublished (5.5).
        $event = Event::create($data);

        return redirect()
            ->route('dashboard.events.show', $event)
            ->with('status', 'Event created.');
    }

    /**
     * Show one of the Company's Events. Cross-Company rows never match the
     * tenant scope and surface as 404. (Requirements 1.5, 5.1)
     *
     * Loads the Event's Ticket_Types (for the comp-issuance picker) and its
     * most recent Orders (so an Admin can cancel/refund from here). Both are
     * tenant-scoped by the same `dashboard.tenant` binding as the Event.
     */
    public function show(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $ticketTypes = $event->ticketTypes()->latest()->get();

        $recentOrders = $event->orders()
            ->latest()
            ->limit(20)
            ->get();

        return view('dashboard.events.show', [
            'event' => $event,
            'ticketTypes' => $ticketTypes,
            'recentOrders' => $recentOrders,
        ]);
    }

    /**
     * Update an Event and persist the updated details. (Requirement 5.3)
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $event->update($this->validated($request));

        // Updated details (name, venue, start time) show on the public
        // storefront listing when the Event is published, so refresh the cache.
        $this->storefrontListing->forget($event->company);

        return redirect()
            ->route('dashboard.events.show', $event)
            ->with('status', 'Event updated.');
    }

    /**
     * Publish an Event, making its public page available. (Requirement 5.4)
     */
    public function publish(Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $event->publish();

        // Publishing adds the Event to the public storefront listing; refresh
        // the cache so the next storefront request includes it. (Requirement 8.2)
        $this->storefrontListing->forget($event->company);

        return redirect()
            ->route('dashboard.events.show', $event)
            ->with('status', 'Event published.');
    }

    /**
     * Unpublish an Event, blocking Customer view/purchase. (Requirement 5.5)
     */
    public function unpublish(Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $event->unpublish();

        // Unpublishing removes the Event from the public storefront listing;
        // refresh the cache so it no longer appears. (Requirement 5.5)
        $this->storefrontListing->forget($event->company);

        return redirect()
            ->route('dashboard.events.show', $event)
            ->with('status', 'Event unpublished.');
    }

    /**
     * Validate Event create/update input. `capacity` is optional (NULL =
     * unlimited overall capacity). (Requirements 5.1, 5.2)
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'venue' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'primary_colour' => ['nullable', 'string', 'max:7'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'ticket_field_defs' => ['nullable', 'array'],
        ]);
    }
}
