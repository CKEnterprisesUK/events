<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Event;
use App\Rules\SafeUpload;
use App\Services\AuditLogger;
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
        private readonly AuditLogger $audit,
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

        $this->audit->record(
            action: AuditLog::EVENT_CREATED,
            auditable: $event,
            summary: 'Created event "'.$event->name.'"',
        );

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

        // Overview screen: at-a-glance stats, capacity advisory and the
        // event-details edit form. Location/tickets/share/orders now each live
        // on their own dedicated screen. (Requirement 5.1)
        return view('dashboard.events.show', $this->sharedViewData($event) + [
            'capacity' => $this->readiness->capacity($event),
            'report' => $this->reports->for($event),
        ]);
    }

    /**
     * Show the dedicated create screen. Kept intentionally minimal — the fuller
     * details (description, hero, capacity, location, ticket types) are added on
     * the manage screens afterwards, guided by the setup checklist. (Req 5.1, 5.2)
     */
    public function create(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.create');
    }

    /**
     * The "Where" screen: a single form owning venue name, location type
     * (in person / online), address and the draggable map pin. Replaces the old
     * Location tab and its hidden-name workaround. (Requirements 4.1, 4.3, 4.9)
     */
    public function location(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.location', $this->sharedViewData($event));
    }

    /**
     * The Tickets screen: ticket-type management + complimentary issuance.
     * (Requirements 6.1, 6.3, 2.6, 18.1)
     */
    public function tickets(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.tickets', $this->sharedViewData($event) + [
            'ticketTypes' => $event->ticketTypes()->latest()->get(),
            'eventRemaining' => $event->overallRemaining(),
            // The advisory event-vs-types capacity comparison lives on this
            // screen now, next to the overall-capacity field it explains.
            'capacity' => $this->readiness->capacity($event),
        ]);
    }

    /**
     * The Share screen: public link + downloadable QR code. (Requirements 4.1, 4.2, 4.4)
     */
    public function share(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.share', $this->sharedViewData($event) + [
            'publicUrl' => route('event.page', [
                'companySlug' => $event->company->slug,
                'event' => $event->id,
            ]),
        ]);
    }

    /**
     * The Orders screen: recent orders with cancel/refund. (Requirements 10.1, 17.1)
     */
    public function orders(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.orders', $this->sharedViewData($event) + [
            'recentOrders' => $event->orders()->latest()->limit(20)->get(),
        ]);
    }

    /**
     * The History screen: this Event's own audit trail (created/updated/
     * published/scan-reset), most recent first, plus the "reset check-ins"
     * control. Gated on ACTION_MANAGE_EVENTS to view (the same gate as every
     * other manage screen); the reset action itself is gated more tightly on
     * ACTION_RESET_SCANS in resetScans().
     *
     * Only rows whose `auditable` IS this Event appear here — the audit_logs
     * table has no global tenant scope, so we additionally pin `company_id` to
     * this Event's Company as a defence-in-depth measure alongside the
     * polymorphic subject filter. (Security — accountability / audit trail)
     */
    public function history(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $logs = AuditLog::query()
            ->with(['actor', 'impersonator'])
            ->where('company_id', $event->company_id)
            ->where('auditable_type', $event->getMorphClass())
            ->where('auditable_id', $event->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('dashboard.events.history', $this->sharedViewData($event) + [
            'logs' => $logs,
            // Confirmed orders currently carrying a check-in — the count the
            // reset control clears. Drives the button's copy and disabled state.
            'scannedCount' => $event->orders()->whereNotNull('scanned_at')->count(),
        ]);
    }

    /**
     * Reset (clear) every check-in for this Event so the door can re-scan from
     * a clean slate. Sets `scanned_at`/`scanned_by` back to NULL on all of the
     * Event's Orders and records an audit entry with the number cleared.
     *
     * Gated on ACTION_RESET_SCANS, which the role matrix grants to the Owner
     * and Admin only (NOT Box_Office): it wipes operational check-in state for
     * a whole event. The Event is tenant-scoped by the `dashboard.tenant`
     * group, so a foreign Event 404s and the update can only ever touch this
     * Company's Orders. (Security — least privilege / accountability)
     */
    public function resetScans(Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_RESET_SCANS);

        $cleared = $event->orders()
            ->whereNotNull('scanned_at')
            ->update([
                'scanned_at' => null,
                'scanned_by' => null,
            ]);

        $this->audit->record(
            action: AuditLog::EVENT_SCANS_RESET,
            auditable: $event,
            summary: 'Reset check-ins for "'.$event->name.'"',
            context: ['cleared' => $cleared],
        );

        return redirect()
            ->route('dashboard.events.history', $event)
            ->with('status', $cleared === 1
                ? '1 check-in was reset.'
                : $cleared.' check-ins were reset.');
    }

    /**
     * Data every manage screen needs to render the section nav + publish
     * checklist wrapper: the Event itself and its readiness report. Kept in one
     * place so the screens stay consistent and cheap. (Requirement 1.4)
     *
     * @return array<string, mixed>
     */
    private function sharedViewData(Event $event): array
    {
        return [
            'event' => $event,
            // Drives the pinned setup checklist shown alongside every screen.
            'readiness' => $this->readiness->checklist($event),
        ];
    }

    /**
     * Update an Event and persist the updated details. (Requirement 5.3)
     */
    public function update(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        // The Overview form owns only the core details (name, when, capacity,
        // description, hero image). Venue and the full location are edited on
        // the dedicated "Where" screen via updateLocation(), so this request
        // neither requires nor touches any location field. (Requirement 5.3)
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'starts_at' => $this->startsAtRules($request, $event),
            'capacity' => ['nullable', 'integer', 'min:1'],
            'primary_colour' => ['nullable', 'string', 'max:7'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'poster' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096', new SafeUpload],
        ], $this->dateMessages());

        unset($data['poster']);
        $data = $this->applyPoster($request, $data, $event);

        $event->update($data);

        $this->audit->record(
            action: AuditLog::EVENT_UPDATED,
            auditable: $event,
            summary: 'Updated event "'.$event->name.'"',
        );

        // Updated details (name, start time) show on the public storefront
        // listing when the Event is published, so refresh the cache.
        $this->storefrontListing->forget($event->company);

        return redirect()
            ->route('dashboard.events.show', $event)
            ->with('status', 'Event updated.');
    }

    /**
     * Persist the "Where" screen. Validates ONLY the location fields (mode,
     * venue, address, pin) so the section form no longer needs to carry the
     * event's name as a hidden input to survive the full update() validation.
     * Reuses applyLocationAndPoster() for the geocode/pin resolution, then
     * updates just those columns — every other Event attribute is untouched.
     * (Requirements 4.1, 4.2, 4.3, 4.4, 4.5)
     */
    public function updateLocation(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            'location_mode' => ['required', Rule::in(Event::LOCATION_MODES)],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Resolve the pin (geocode a changed in-person address, or honour a
        // dragged pin) exactly as the combined form did. No poster on this form.
        $data = $this->applyLocationAndPoster($request, $data, $event);

        $event->update($data);

        // Venue shows on the public storefront listing; refresh its cache.
        $this->storefrontListing->forget($event->company);

        return redirect()
            ->route('dashboard.events.location', $event)
            ->with('status', 'Location updated.');
    }

    /**
     * Persist the overall event capacity (the shared-pool ceiling) from the
     * Tickets screen. Its own tiny action so capacity saves independently of
     * the Overview details form. `null` = unlimited. (Requirements 3.1, 5.3)
     */
    public function updateCapacity(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $event->update(['capacity' => $data['capacity'] ?? null]);

        // Overall capacity can gate shared-pool availability shown publicly.
        $this->storefrontListing->forget($event->company);

        return redirect()
            ->route('dashboard.events.tickets', $event)
            ->with('status', 'Overall capacity updated.');
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

        $this->audit->record(
            action: AuditLog::EVENT_PUBLISHED,
            auditable: $event,
            summary: 'Published event "'.$event->name.'"',
        );

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

        $this->audit->record(
            action: AuditLog::EVENT_UNPUBLISHED,
            auditable: $event,
            summary: 'Unpublished event "'.$event->name.'"',
        );

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
            'starts_at' => $this->startsAtRules($request, null),
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
            // The hero image is a file, not a persisted scalar; it is stored by
            // applyLocationAndPoster() and excluded from the returned attributes.
            'poster' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096', new SafeUpload],
        ], $this->dateMessages());

        // Return only the persistable scalar attributes; the poster file is
        // handled separately in store()/update().
        unset($validated['poster']);

        return $validated;
    }

    /**
     * Validation rules for the Event start date/time. Always `nullable|date`;
     * additionally requires the value to be now-or-later, but ONLY when it is a
     * NEW value — creating an Event, or changing an existing Event's start to a
     * different value. This lets an organiser keep (and re-save) an Event whose
     * start has already passed without being forced to move it, while still
     * blocking anyone from newly scheduling an Event in the past.
     *
     * @return list<string>
     */
    private function startsAtRules(Request $request, ?Event $event): array
    {
        $rules = ['nullable', 'date'];

        $submitted = $request->input('starts_at');

        if ($submitted === null || $submitted === '') {
            return $rules;
        }

        // Compare against the stored value (to the minute) so merely re-saving
        // an unchanged past date does not trip the "not in the past" rule.
        $current = $event?->starts_at?->format('Y-m-d\TH:i');
        $unchanged = $current !== null
            && $current === \Illuminate\Support\Carbon::parse($submitted)->format('Y-m-d\TH:i');

        if (! $unchanged) {
            $rules[] = 'after_or_equal:now';
        }

        return $rules;
    }

    /**
     * Friendly messages for the date rules shared across the Event forms.
     *
     * @return array<string, string>
     */
    private function dateMessages(): array
    {
        return [
            'starts_at.after_or_equal' => __('The event start date can’t be in the past.'),
        ];
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

        return $this->applyPoster($request, $data, $event);
    }

    /**
     * Store an uploaded hero image (if present) and set `poster_path` on the
     * attribute array — same disk/dir/delete-old semantics as
     * {@see \App\Http\Controllers\BrandingController}. Shared by store()
     * (via applyLocationAndPoster) and the Overview update(). (Requirement 5.1)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyPoster(Request $request, array $data, ?Event $event): array
    {
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
