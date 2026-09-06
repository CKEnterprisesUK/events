<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\BrandingImageStore;
use App\Services\EventReadiness;
use App\Services\EventReportService;
use App\Services\GeocodingService;
use App\Services\QrService;
use App\Services\RoleAuthorization;
use App\Services\StorefrontListing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

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
    /**
     * Where uploaded hero images (posters) live on the `public` disk.
     * Matches {@see \App\Http\Controllers\BrandingController} so a per-event
     * hero and a company hero share the same disk and directory. (Requirement 5.1)
     */
    private const POSTER_DIRECTORY = 'branding/posters';

    public function __construct(
        private StorefrontListing $storefrontListing,
        private EventReadiness $readiness,
        private EventReportService $reports,
        private GeocodingService $geocoder,
        private BrandingImageStore $images,
    ) {}

    /**
     * List the authenticated Company's Events. (Requirement 5.1)
     */
    public function index(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        // Eager-load the Company so the listing's hero thumbnail can fall back
        // to the company poster (`$event->poster_path ?? $event->company->poster_path`)
        // without an N+1 query per row. (Requirements 5.4, 5.5, 5.6)
        $events = Event::query()->with('company')->latest()->get();

        return view('dashboard.events.index', ['events' => $events]);
    }

    /**
     * Create an Event scoped to the Admin's Company. (Requirement 5.1, 5.2)
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        // The create FORM asks only for the essentials (name + optional
        // start/venue); the hero image, capacity, description and location are
        // added later on the manage page, guided by the setup checklist.
        //
        // Validation stays tolerant of the fuller payload, though: `poster` and
        // the location fields are still accepted and applied when present, so
        // programmatic callers (and the event-experience-polish hero-on-create
        // contract) keep working. `location_mode` is optional here and falls
        // back to the model default (in_person) when the slim form omits it.
        $data = $this->validated($request, requireLocationMode: false);
        $data = $this->applyLocationAndPoster($request, $data, null);

        // company_id is auto-filled from the resolved tenant by the
        // BelongsToCompany trait; publish state defaults to unpublished and
        // location_mode defaults to in_person via the model's $attributes (5.5).
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
            // Overall remaining capacity computed once (null = unlimited) so the
            // ticket-type and comp partials can derive mode-aware availability
            // via TicketType::availabilityFor() without an N+1. (Requirements 2.6, 1.4)
            'eventRemaining' => $event->overallRemaining(),
            // Publish-readiness checklist and the advisory capacity comparison
            // (Requirements 1.4, 2.1, 3.1).
            'readiness' => $this->readiness->checklist($event),
            'capacity' => $this->readiness->capacity($event),
            // The public event page URL for the share panel; live only once the
            // Event is published (Requirements 4.1, 4.2, 4.4).
            'publicUrl' => route('event.page', [
                'companySlug' => $event->company->slug,
                'event' => $event->id,
            ]),
            // At-a-glance accounting summary from the single reporting source of
            // truth (Requirement 5.1).
            'report' => $this->reports->for($event),
        ]);
    }

    /**
     * Update an Event and persist the updated details. (Requirement 5.3)
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $this->validated($request);
        $data = $this->applyLocationAndPoster($request, $data, $event);

        $event->update($data);

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

        // The Event model is the single source of truth for publishability. If
        // any prerequisite is unmet, refuse the publish and surface the blocker
        // messages on the show page — leaving the Event unpublished and the
        // storefront cache untouched. (Requirements 1.1, 1.2)
        $blockers = $event->publishBlockers();

        if ($blockers !== []) {
            return redirect()
                ->route('dashboard.events.show', $event)
                ->with('publish_errors', array_values($blockers));
        }

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
     * Download a PNG QR code that points at the Event's public page. Available
     * for both draft and published Events so an Admin can prepare printed
     * material ahead of go-live — the encoded URL only becomes reachable once
     * the Event is published. (Requirements 4.3, 4.5)
     *
     * QR rendering needs the GD extension; if it is unavailable the writer
     * throws, and we surface a 500 rather than ever returning a broken image or
     * partial bytes. (Requirement 4.7)
     */
    public function qr(Event $event, QrService $qr): Response
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $url = route('event.page', [
            'companySlug' => $event->company->slug,
            'event' => $event->id,
        ]);

        try {
            $png = $qr->png($url, 512);
        } catch (\Throwable $e) {
            abort(500, 'QR code generation is unavailable on this server.');
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="event-'.$event->id.'-qr.png"',
        ]);
    }

    /**
     * Validate Event create/update input. `capacity` is optional (NULL =
     * unlimited overall capacity). (Requirements 5.1, 5.2)
     *
     * Location fields (`location_mode`/`address`/`latitude`/`longitude`) are
     * validated and returned as persistable attributes; the `poster` file is
     * validated here but NOT returned — it is a file, handled separately in
     * store()/update() via {@see applyLocationAndPoster()}. (Requirements 4.1,
     * 4.2, 5.2, 5.3)
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $requireLocationMode = true): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'venue' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            // The manage-page Overview/Location forms always submit location_mode
            // and require it; the slim create form omits it, so store() relaxes
            // this to `sometimes` and lets the model default (in_person) apply.
            'location_mode' => [$requireLocationMode ? 'required' : 'sometimes', Rule::in(Event::LOCATION_MODES)],
            'address' => ['nullable', 'string', 'max:500'],
            // lat/lng come from the hidden inputs the mini-map writes; validated
            // but normally overwritten by a successful geocode on address change.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'primary_colour' => ['nullable', 'string', 'max:7'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'ticket_field_defs' => ['nullable', 'array'],
            // The hero image is a file, not a persisted scalar; it is stored by
            // applyLocationAndPoster() and excluded from the returned attributes.
            'poster' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
        ]);

        // Return only the persistable scalar attributes; the poster file is
        // handled separately in store()/update().
        unset($validated['poster']);

        return $validated;
    }

    /**
     * Post-validation step shared by store()/update(): resolve the map pin from
     * the location fields (geocoding an in-person address) and store an uploaded
     * hero image. Returns the augmented attribute array ready to persist.
     *
     * @param  array<string, mixed>  $data  the validated scalar attributes
     * @param  Event|null  $event  the existing Event on update, null on create
     * @return array<string, mixed>
     */
    private function applyLocationAndPoster(Request $request, array $data, ?Event $event): array
    {
        if (($data['location_mode'] ?? null) === Event::LOCATION_IN_PERSON) {
            $address = trim((string) ($data['address'] ?? ''));

            // On create there is nothing to compare against; on update we only
            // re-geocode when the address text actually changed. (Requirement 4.4)
            $addressChanged = $event === null || $address !== (string) ($event->address ?? '');

            if ($address !== '' && $addressChanged) {
                $result = $this->geocoder->geocode($address);

                if ($result !== null) {
                    // A successful lookup wins over whatever the hidden inputs
                    // submitted. (Requirement 4.2)
                    $data['latitude'] = $result->latitude;
                    $data['longitude'] = $result->longitude;
                } else {
                    // Save without coordinates: keep whatever the hidden pin
                    // inputs submitted (may be a manual pin or null) so the
                    // manager can set the location by dragging. (Requirement 4.5)
                    session()->flash(
                        'geocode_warning',
                        'We could not locate that address. Save it, then drag the map pin to set the location manually.',
                    );
                }
            } elseif (! $addressChanged) {
                // Address unchanged: do not re-geocode. Honour a dragged pin by
                // keeping the submitted hidden lat/lng; when those inputs are
                // absent, fall back to the stored coordinates so an unchanged
                // save never wipes an existing pin. (Requirements 4.3, 4.4)
                if (! $request->filled('latitude')) {
                    $data['latitude'] = $event?->latitude;
                }
                if (! $request->filled('longitude')) {
                    $data['longitude'] = $event?->longitude;
                }
            }
        } else {
            // Online: there is no physical location, so clear all map data.
            $data['address'] = null;
            $data['latitude'] = null;
            $data['longitude'] = null;
        }

        // Hero image upload — same disk/dir/delete-old semantics as
        // BrandingController. (Requirement 5.1)
        if ($request->hasFile('poster')) {
            $data['poster_path'] = $this->images->store(
                $request->file('poster'),
                self::POSTER_DIRECTORY,
                $event?->poster_path,
            );
        }

        return $data;
    }
}
