<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventSponsor;
use App\Rules\SafeUpload;
use App\Services\BrandingImageStore;
use App\Services\EventReadiness;
use App\Services\RoleAuthorization;
use App\Services\StorefrontListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Company-dashboard controller for managing an Event's sponsors.
 *
 * Sponsors are a repeatable per-Event list (a logo, optional store-page name/
 * website/bio, and an `on_ticket` flag). Every sponsor shows on the public
 * event/storefront page in `sort_order`; up to {@see self::MAX_ON_TICKET} may
 * be flagged to print on the ticket PDF. All write actions are gated on the
 * Admin `ACTION_MANAGE_EVENTS`, matching the rest of event management.
 *
 * Nested under an Event (`/dashboard/events/{event}/sponsors`). Both the parent
 * Event and its sponsors are Company-owned, so the global `company_id` tenant
 * scope constrains every query to the acting Company; another Company's Event
 * or sponsor surfaces as 404 with no modification. (Requirements 1.5)
 */
class EventSponsorController extends Controller
{
    /** Where uploaded sponsor logos live on the `public` disk. */
    private const SPONSOR_DIRECTORY = 'branding/sponsors';

    /** How many sponsors may be flagged to print on the ticket. */
    public const MAX_ON_TICKET = 3;

    /** Hard ceiling on sponsors per Event, to bound the public page. */
    private const MAX_SPONSORS = 30;

    public function __construct(
        private readonly BrandingImageStore $imageStore,
        private readonly EventReadiness $readiness,
        private readonly StorefrontListing $storefrontListing,
    ) {}

    /**
     * The sponsors management screen for an Event: the current sponsors in
     * order, plus the organiser's other events (as copy-from sources).
     */
    public function index(Event $event)
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        return view('dashboard.events.sponsors', [
            'event' => $event,
            'readiness' => $this->readiness->checklist($event),
            'sponsors' => $event->sponsors()->get(),
            'onTicketCount' => $event->sponsors()->where('on_ticket', true)->count(),
            'maxOnTicket' => self::MAX_ON_TICKET,
            // Other events belonging to the same Company, as copy sources.
            'copyableEvents' => Event::query()
                ->where('id', '!=', $event->id)
                ->whereHas('sponsors')
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Add a sponsor to the Event. A logo image is required; name/website/bio
     * are optional store-page details. New sponsors sort to the end.
     */
    public function store(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        if ($event->sponsors()->count() >= self::MAX_SPONSORS) {
            throw ValidationException::withMessages([
                'image' => 'You can add up to '.self::MAX_SPONSORS.' sponsors per event.',
            ]);
        }

        $data = $this->validateSponsor($request, requireImage: true);

        $this->guardOnTicketLimit($event, adding: (bool) ($data['on_ticket'] ?? false));

        $path = $this->imageStore->store($request->file('image'), self::SPONSOR_DIRECTORY);

        $event->sponsors()->create([
            'image_path' => $path,
            'name' => $data['name'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'bio' => $data['bio'] ?? null,
            'on_ticket' => (bool) ($data['on_ticket'] ?? false),
            'sort_order' => (int) ($event->sponsors()->max('sort_order') ?? -1) + 1,
        ]);

        $this->storefrontListing->forget($event->company);

        return $this->back($event, 'Sponsor added.');
    }

    /**
     * Update one sponsor's details (and optionally replace its logo).
     */
    public function update(Request $request, Event $event, EventSponsor $sponsor): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);
        $this->ensureBelongsToEvent($event, $sponsor);

        $data = $this->validateSponsor($request, requireImage: false);

        $this->guardOnTicketLimit(
            $event,
            adding: (bool) ($data['on_ticket'] ?? false) && ! $sponsor->on_ticket,
            excludingSponsorId: $sponsor->id,
        );

        $attributes = [
            'name' => $data['name'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'bio' => $data['bio'] ?? null,
            'on_ticket' => (bool) ($data['on_ticket'] ?? false),
        ];

        if ($request->hasFile('image')) {
            $attributes['image_path'] = $this->imageStore->store(
                $request->file('image'),
                self::SPONSOR_DIRECTORY,
                $sponsor->image_path,
            );
        }

        $sponsor->update($attributes);

        $this->storefrontListing->forget($event->company);

        return $this->back($event, 'Sponsor updated.');
    }

    /**
     * Remove one sponsor and delete its logo file.
     */
    public function destroy(Event $event, EventSponsor $sponsor): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);
        $this->ensureBelongsToEvent($event, $sponsor);

        $this->imageStore->delete($sponsor->image_path);
        $sponsor->delete();

        $this->storefrontListing->forget($event->company);

        return $this->back($event, 'Sponsor removed.');
    }

    /**
     * Re-order the Event's sponsors from a submitted list of sponsor ids. Ids
     * not belonging to the Event are ignored; the given order becomes the new
     * `sort_order` (0-based).
     */
    public function reorder(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        $ownIds = $event->sponsors()->pluck('id')->all();
        $order = 0;

        foreach ($data['order'] as $id) {
            if (! in_array((int) $id, $ownIds, true)) {
                continue;
            }

            $event->sponsors()->whereKey($id)->update(['sort_order' => $order++]);
        }

        $this->storefrontListing->forget($event->company);

        return $this->back($event, 'Sponsor order updated.');
    }

    /**
     * Copy every sponsor from another of the Company's Events onto this Event,
     * appending them after the current sponsors. Logo image files are duplicated
     * so deleting one event's sponsor never affects the other. Copied sponsors
     * come in NOT flagged for the ticket, so the per-event on-ticket cap is
     * never breached by a copy (the organiser opts them in afterwards).
     */
    public function copy(Request $request, Event $event): RedirectResponse
    {
        Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

        $data = $request->validate([
            'source_event_id' => ['required', 'integer'],
        ]);

        // Tenant-scoped: a source outside the acting Company won't be found.
        $source = Event::query()
            ->where('id', $data['source_event_id'])
            ->firstOrFail();

        if ($source->id === $event->id) {
            throw ValidationException::withMessages([
                'source_event_id' => 'Choose a different event to copy sponsors from.',
            ]);
        }

        $order = (int) ($event->sponsors()->max('sort_order') ?? -1) + 1;
        $copied = 0;

        foreach ($source->sponsors()->get() as $sponsor) {
            if ($event->sponsors()->count() + $copied >= self::MAX_SPONSORS) {
                break;
            }

            $newPath = $this->duplicateImage($sponsor->image_path);

            if ($newPath === null) {
                continue;
            }

            $event->sponsors()->create([
                'image_path' => $newPath,
                'name' => $sponsor->name,
                'website_url' => $sponsor->website_url,
                'bio' => $sponsor->bio,
                // Copies never auto-print on the ticket; opt in afterwards.
                'on_ticket' => false,
                'sort_order' => $order++,
            ]);

            $copied++;
        }

        $this->storefrontListing->forget($event->company);

        return $this->back(
            $event,
            $copied === 0
                ? 'That event had no sponsors to copy.'
                : $copied.' '.str('sponsor')->plural($copied).' copied.',
        );
    }

    // ---- Helpers -------------------------------------------------------------

    /**
     * Validate the sponsor form. The image is required on create and optional
     * on update (a missing file keeps the current logo).
     *
     * @return array<string, mixed>
     */
    private function validateSponsor(Request $request, bool $requireImage): array
    {
        return $request->validate([
            'image' => [
                $requireImage ? 'required' : 'nullable',
                'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192', new SafeUpload,
            ],
            'name' => ['nullable', 'string', 'max:255'],
            'website_url' => ['nullable', 'string', 'url', 'max:255'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'on_ticket' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Refuse the write when it would push the number of on-ticket sponsors past
     * the cap. `$adding` is whether this write newly flags a sponsor on-ticket.
     */
    private function guardOnTicketLimit(Event $event, bool $adding, ?int $excludingSponsorId = null): void
    {
        if (! $adding) {
            return;
        }

        $current = $event->sponsors()
            ->where('on_ticket', true)
            ->when($excludingSponsorId !== null, fn ($q) => $q->whereKeyNot($excludingSponsorId))
            ->count();

        if ($current >= self::MAX_ON_TICKET) {
            throw ValidationException::withMessages([
                'on_ticket' => 'You can show at most '.self::MAX_ON_TICKET.' sponsors on the ticket. Turn one off first.',
            ]);
        }
    }

    /**
     * Duplicate a stored sponsor image on the public disk, returning the new
     * relative path, or null when the source file is missing.
     */
    private function duplicateImage(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'png';
        $newPath = self::SPONSOR_DIRECTORY.'/'.str()->uuid().'.'.$extension;

        $disk->put($newPath, $disk->get($path));

        return $newPath;
    }

    /**
     * Guard that the sponsor belongs to the Event. Both are tenant-scoped, but a
     * sponsor of another Event in the same Company must not be reachable via
     * this Event's nested route.
     */
    private function ensureBelongsToEvent(Event $event, EventSponsor $sponsor): void
    {
        abort_unless($sponsor->event_id === $event->id, 404);
    }

    /**
     * Redirect back to the sponsors screen with a status message.
     */
    private function back(Event $event, string $status): RedirectResponse
    {
        return redirect()
            ->route('dashboard.events.sponsors', $event)
            ->with('status', $status);
    }
}
