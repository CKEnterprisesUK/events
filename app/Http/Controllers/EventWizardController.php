<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Event;
use App\Rules\SafeUpload;
use App\Services\AuditLogger;
use App\Services\BrandingImageStore;
use App\Services\EventReadiness;
use App\Services\GeocodingService;
use App\Services\RoleAuthorization;
use App\Services\StorefrontListing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Server-driven multi-step create wizard for Events.
 *
 * Rather than hold an entire event (name, description, date, venue, ticket
 * types, branding, sponsors) in one un-submitted client-side form, the wizard
 * creates the Event as a *draft* on step one and then edits that draft row on
 * every subsequent step. Each step is a real HTTP request — a GET to render and
 * a POST/PATCH to persist-and-advance — so state survives refresh and
 * back/forward navigation, and each step reuses the existing per-section
 * validators and update logic. (Requirements 4.1, 4.13, 4.15;
 * Design: "Server-driven wizard rationale")
 *
 * Every action is gated by `ACTION_MANAGE_EVENTS` (the same gate every manage
 * screen enforces). The draft Event is created via the `BelongsToCompany`
 * trait, so it is scoped to the active tenant and a foreign event 404s at every
 * step. (Requirement 4.15)
 *
 * The `branding` and `sponsors` steps are only reachable when the user holds
 * the `settings` permission; otherwise the flow ends after `tickets`. This
 * mirrors the existing branding/sponsor permission gate. (Requirement 4.14)
 */
final class EventWizardController extends Controller
{
    /**
     * The ordered wizard steps. `branding` and `sponsors` are filtered out for
     * users without the `settings` permission by {@see stepsFor()}.
     *
     * @var list<string>
     */
    private const STEPS = ['basics', 'when', 'venue', 'tickets', 'branding', 'sponsors'];

    /**
     * Where uploaded hero images (posters) live on the `public` disk. Matches
     * {@see EventController} and {@see BrandingController} so a wizard-uploaded
     * hero shares the same disk/directory as one uploaded on the manage screen.
     */
    private const POSTER_DIRECTORY = 'branding/posters';

    /** Where uploaded logos live on the `public` disk. Matches BrandingController. */
    private const LOGO_DIRECTORY = 'branding/logos';

    /** Where uploaded sponsor logos live. Matches EventSponsorController. */
    private const SPONSOR_DIRECTORY = 'branding/sponsors';

    /** Maximum sponsors per Event, mirroring {@see EventSponsorController}. */
    private const MAX_SPONSORS = 30;

    public function __construct(
        private readonly EventReadiness $readiness,
        private readonly AuditLogger $audit,
        private readonly GeocodingService $geocoder,
        private readonly BrandingImageStore $images,
        private readonly StorefrontListing $storefrontListing,
    ) {}

    /**
     * Step 1: render the wizard's first step ("basics"). Kept at the existing
     * `events.create` route/name so the "New event" links keep working — they
     * now open the wizard instead of the old slim form. (Requirements 4.1, 4.2)
     */
    public function start(): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return $this->renderStep(null, 'basics');
    }

    /**
     * Create the tenant-scoped draft Event from the basics step (name +
     * description) and advance to the `when` step. `company_id` is auto-filled
     * from the resolved tenant by the `BelongsToCompany` trait and the event is
     * unpublished by default, so an abandoned wizard simply leaves a resumable
     * draft. (Requirements 4.2, 4.13, 4.15)
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $event = Event::create($data);

        $this->audit->record(
            action: AuditLog::EVENT_CREATED,
            auditable: $event,
            summary: 'Created event "'.$event->name.'"',
        );

        return redirect()->route('dashboard.events.wizard.step', [
            'event' => $event,
            'step' => 'when',
        ]);
    }

    /**
     * Render a per-step view for the draft Event. Invalid step slugs 404; the
     * `branding`/`sponsors` steps are gated behind the `settings` permission.
     * (Requirements 4.1, 4.14)
     *
     * NOTE (task 7.1): full per-step views are built in task 7.2 and per-step
     * persistence in task 7.3. For now this renders the step's dedicated view
     * when it exists, otherwise a simple placeholder, so the routes resolve.
     */
    public function step(Event $event, string $step): View
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $this->assertValidStep($step);
        $this->gate($step);

        return $this->renderStep($event, $step);
    }

    /**
     * Persist a step and advance (or finish). Invalid step slugs 404; the
     * branding/sponsors steps are gated behind `settings`.
     * (Requirements 4.9, 4.11, 4.13)
     *
     * Each step delegates to the same validation + persistence logic the
     * dedicated manage screens use (Design: "Per-step persistence"), so the
     * wizard never invents its own rules:
     *
     *   - when     → Event `starts_at` update, reusing the not-in-past rule.
     *   - venue    → location + geocode, mirroring EventController::updateLocation.
     *   - tickets  → nothing to persist here: the accordion's own per-row forms
     *                POST straight to the ticket-type routes, so the wizard's
     *                tickets submit is a pure Continue/Skip that just advances.
     *   - branding → header image + logo choice, mirroring the branding update.
     *   - sponsors → optional sponsor add, mirroring EventSponsorController::store.
     *
     * A failed `$request->validate()` throws {@see ValidationException}, which
     * Laravel turns into a redirect back to this step with the errors and old
     * input — so the user stays on the step and each field shows its @error
     * message (Requirement 4.10). Skipping an optional step is a GET to the
     * next step (see `_nav.blade.php`), so it never reaches here and never
     * validates (Requirement 4.8). On the last reachable step, Finish lands on
     * the manage screen (Requirement 4.13).
     */
    public function save(Request $request, Event $event, string $step): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $this->assertValidStep($step);
        $this->gate($step);

        match ($step) {
            'basics' => $this->saveBasics($request, $event),
            'when' => $this->saveWhen($request, $event),
            'venue' => $this->saveVenue($request, $event),
            'tickets' => null, // Ticket types persist via their own routes.
            'branding' => $this->saveBranding($request, $event),
            'sponsors' => $this->saveSponsors($request, $event),
            default => null,
        };

        $next = $this->nextStep($step);

        if ($next === null) {
            return redirect()
                ->route('dashboard.events.show', $event)
                ->with('status', 'Event created.');
        }

        return redirect()->route('dashboard.events.wizard.step', [
            'event' => $event,
            'step' => $next,
        ]);
    }

    /**
     * `basics` is normally handled by {@see store()} (draft creation). A save
     * arriving here means an existing draft is re-saving its name/description
     * (e.g. via back navigation), so apply the same name/description rules and
     * persist. (Requirement 4.2)
     */
    private function saveBasics(Request $request, Event $event): void
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $event->update($data);
        $this->storefrontListing->forget($event->company);
    }

    /**
     * Persist the event start time. Reuses the same "not in the past unless
     * unchanged" rule as {@see EventController::update()} so the wizard and the
     * manage screen agree. `starts_at` is optional here (a date can be added
     * before publishing). (Requirements 4.3, Design: "Per-step persistence")
     */
    private function saveWhen(Request $request, Event $event): void
    {
        $data = $request->validate([
            'starts_at' => $this->startsAtRules($request, $event),
        ], [
            'starts_at.after_or_equal' => __('The event start date can’t be in the past.'),
        ]);

        $event->update(['starts_at' => $data['starts_at'] ?? null]);
        $this->storefrontListing->forget($event->company);
    }

    /**
     * Persist the venue/location, mirroring {@see EventController::updateLocation()}:
     * the same location rules, the same geocode-on-address-change behaviour, and
     * the same online-clears-the-pin handling. (Requirement 4.4)
     */
    private function saveVenue(Request $request, Event $event): void
    {
        $data = $request->validate([
            'location_mode' => ['required', Rule::in(Event::LOCATION_MODES)],
            'venue' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $data = $this->resolveLocation($request, $data, $event);

        $event->update($data);
        $this->storefrontListing->forget($event->company);
    }

    /**
     * Persist branding: an optional header image and the logo choice. Mirrors
     * the branding fields collected on the dedicated Branding screen and the
     * image-store semantics of {@see BrandingController}/{@see EventController}.
     *
     * `logo_choice` = `account` clears any per-event logo so the event falls
     * back to the account default; `custom` keeps (or replaces, if a file is
     * uploaded) the per-event logo. (Requirement 4.6)
     */
    private function saveBranding(Request $request, Event $event): void
    {
        $data = $request->validate([
            'poster' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:8192', new SafeUpload],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120', new SafeUpload],
            'logo_choice' => ['nullable', Rule::in(['account', 'custom'])],
        ]);

        $attributes = [];

        if ($request->hasFile('poster')) {
            $attributes['poster_path'] = $this->images->store(
                $request->file('poster'),
                self::POSTER_DIRECTORY,
                $event->poster_path,
            );
        }

        $choice = $data['logo_choice'] ?? null;

        if ($choice === 'account') {
            // Fall back to the account logo: drop any per-event override.
            $attributes['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            // Custom logo for this event only.
            $attributes['logo_path'] = $this->images->store(
                $request->file('logo'),
                self::LOGO_DIRECTORY,
                $event->logo_path,
            );
        }

        if ($attributes !== []) {
            $event->update($attributes);
        }
    }

    /**
     * Persist a sponsor when the organiser opted in on the "do you have a
     * sponsor?" gate. Mirrors {@see EventSponsorController::store()} — a logo is
     * required when adding, the ceiling is enforced, and the sponsor sorts to
     * the end. When the gate is left on "not right now" (or no file is
     * supplied) nothing is persisted, keeping the step effectively optional.
     * (Requirement 4.7)
     */
    private function saveSponsors(Request $request, Event $event): void
    {
        // Only add a sponsor when the organiser explicitly opted in AND supplied
        // a logo; otherwise Finish just closes the wizard with no sponsor.
        if ($request->input('has_sponsor') !== '1' || ! $request->hasFile('image')) {
            return;
        }

        if ($event->sponsors()->count() >= self::MAX_SPONSORS) {
            throw ValidationException::withMessages([
                'image' => 'You can add up to '.self::MAX_SPONSORS.' sponsors per event.',
            ]);
        }

        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192', new SafeUpload],
            'name' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'url', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'on_ticket' => ['nullable', 'boolean'],
        ]);

        $path = $this->images->store($request->file('image'), self::SPONSOR_DIRECTORY);

        $event->sponsors()->create([
            'image_path' => $path,
            'name' => $data['name'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'bio' => $data['bio'] ?? null,
            'on_ticket' => (bool) ($data['on_ticket'] ?? false),
            'sort_order' => (int) ($event->sponsors()->max('sort_order') ?? -1) + 1,
        ]);

        $this->storefrontListing->forget($event->company);
    }

    /**
     * Validation rules for the Event start date/time, copied from
     * {@see EventController::startsAtRules()} so the wizard and manage screen
     * apply identical semantics: always `nullable|date`, and additionally
     * `after_or_equal:now` only when the submitted value is new (creating, or
     * changing an existing draft's start to a different value). Re-saving an
     * unchanged past date is allowed.
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

        $current = $event?->starts_at?->format('Y-m-d\TH:i');
        $unchanged = $current !== null
            && $current === Carbon::parse($submitted)->format('Y-m-d\TH:i');

        if (! $unchanged) {
            $rules[] = 'after_or_equal:now';
        }

        return $rules;
    }

    /**
     * Resolve the map pin from the submitted location fields, mirroring
     * {@see EventController::applyLocationAndPoster()} (minus the poster, which
     * the venue step never carries): geocode a changed in-person address,
     * honour a dragged pin when the address is unchanged, and clear all map data
     * for online events. (Requirements 4.4, 4.5)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLocation(Request $request, array $data, Event $event): array
    {
        if (($data['location_mode'] ?? null) === Event::LOCATION_IN_PERSON) {
            $address = trim((string) ($data['address'] ?? ''));
            $addressChanged = $address !== (string) ($event->address ?? '');

            if ($address !== '' && $addressChanged) {
                $result = $this->geocoder->geocode($address);

                if ($result !== null) {
                    $data['latitude'] = $result->latitude;
                    $data['longitude'] = $result->longitude;
                } else {
                    session()->flash(
                        'geocode_warning',
                        'We could not locate that address. Save it, then drag the map pin to set the location manually.',
                    );
                }
            } elseif (! $addressChanged) {
                if (! $request->filled('latitude')) {
                    $data['latitude'] = $event->latitude;
                }
                if (! $request->filled('longitude')) {
                    $data['longitude'] = $event->longitude;
                }
            }
        } else {
            $data['address'] = null;
            $data['latitude'] = null;
            $data['longitude'] = null;
        }

        return $data;
    }

    /**
     * Render the wizard shell for a step. The dedicated step views arrive in
     * task 7.2; until then fall back to a minimal placeholder so the routes
     * render. Shared view data mirrors what the step views will need: the draft
     * event (null on the first step), the reachable steps, the current step and
     * its neighbours.
     */
    private function renderStep(?Event $event, string $step): View
    {
        $steps = $this->stepsFor();

        $data = [
            'event' => $event,
            'steps' => $steps,
            'currentStep' => $step,
            'prevStep' => $this->prevStep($step),
            'nextStep' => $this->nextStep($step),
            'readiness' => $event !== null ? $this->readiness->checklist($event) : null,
        ];

        $view = 'dashboard.events.wizard.'.$step;

        if (view()->exists($view)) {
            return view($view, $data);
        }

        return view('dashboard.events.wizard.placeholder', $data);
    }

    /**
     * The steps reachable by the active user. `branding` and `sponsors` are
     * dropped unless the user holds the `settings` permission, so the flow ends
     * after `tickets` for everyone else. (Requirement 4.14)
     *
     * @return list<string>
     */
    private function stepsFor(): array
    {
        if (Gate::allows(RoleAuthorization::ACTION_MANAGE_SETTINGS)) {
            return self::STEPS;
        }

        return array_values(array_filter(
            self::STEPS,
            static fn (string $step): bool => ! in_array($step, ['branding', 'sponsors'], true),
        ));
    }

    /**
     * The next reachable step after $step, or null when $step is the last one
     * (i.e. finish → manage screen).
     */
    private function nextStep(string $step): ?string
    {
        $steps = $this->stepsFor();
        $index = array_search($step, $steps, true);

        if ($index === false) {
            return null;
        }

        return $steps[$index + 1] ?? null;
    }

    /**
     * The previous reachable step before $step, or null when $step is the
     * first one.
     */
    private function prevStep(string $step): ?string
    {
        $steps = $this->stepsFor();
        $index = array_search($step, $steps, true);

        if ($index === false || $index === 0) {
            return null;
        }

        return $steps[$index - 1] ?? null;
    }

    /**
     * Restrict the branding/sponsors steps to users with the `settings`
     * permission, matching the existing branding/sponsor gate. Other steps are
     * unrestricted beyond the controller-wide `ACTION_MANAGE_EVENTS` gate.
     * (Requirement 4.14)
     */
    private function gate(string $step): void
    {
        if (in_array($step, ['branding', 'sponsors'], true)) {
            Gate::authorize(RoleAuthorization::ACTION_MANAGE_SETTINGS);
        }
    }

    /**
     * 404 for a step slug that is not a known wizard step. Steps that exist but
     * are gated away by permission are handled by {@see gate()} (403), not here.
     */
    private function assertValidStep(string $step): void
    {
        abort_unless(in_array($step, self::STEPS, true), 404);
    }
}
