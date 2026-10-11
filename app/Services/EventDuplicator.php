<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Event;
use App\Models\EventQuestion;
use App\Models\EventSponsor;
use App\Models\TicketType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Duplicates an Event into a fresh, unpublished draft belonging to the same
 * Company, ready for the organiser to edit and publish.
 *
 * What is copied: the Event's own details (name, description, venue, location,
 * capacity, branding overrides and its inline sponsor fields), its Ticket_Types,
 * its attendee questions, and its (repeatable) sponsors. Stored image files —
 * the hero/poster, the event logo, and each sponsor logo — are duplicated on
 * disk so deleting one Event's images never affects the copy.
 *
 * What is deliberately NOT copied: any booking state. The new Event starts with
 * NO orders, tickets or check-ins, so every Ticket_Type's `sold_count` and
 * `reserved_count` are reset to zero. The copy is always created UNPUBLISHED and
 * NOT cancelled, and its `starts_at` is cleared — the organiser must set a new
 * date and re-publish, which also means the copy can never inherit a past date.
 *
 * The whole copy runs in one transaction so a failure never leaves a
 * half-built Event behind. Image duplication happens inside the transaction
 * too; a missing source file is skipped (the field is left null) rather than
 * aborting the copy.
 */
class EventDuplicator
{
    /** Where hero/poster images live on the `public` disk (matches EventController). */
    private const POSTER_DIRECTORY = 'branding/posters';

    /** Where event logo images live on the `public` disk (matches BrandingController). */
    private const LOGO_DIRECTORY = 'branding/logos';

    /** Where sponsor logos live on the `public` disk (matches EventSponsorController). */
    private const SPONSOR_DIRECTORY = 'branding/sponsors';

    /**
     * Duplicate `$source` into a new draft Event and return the new Event.
     *
     * `company_id` is inherited from the source and re-asserted explicitly so
     * the copy always lands in the same tenant even if the global scope's
     * auto-fill is ever bypassed. The caller is responsible for authorisation
     * (the Event is already tenant-scoped by the dashboard binding).
     */
    public function duplicate(Event $source): Event
    {
        return DB::transaction(function () use ($source): Event {
            $copy = $this->copyEvent($source);
            $this->copyTicketTypes($source, $copy);
            $this->copyQuestions($source, $copy);
            $this->copySponsors($source, $copy);

            return $copy->refresh();
        });
    }

    /**
     * Create the new Event row from the source's own attributes, forcing the
     * draft-state fields (unpublished, not cancelled, no start date) and giving
     * it a distinguishing name so it is obvious in the list.
     */
    private function copyEvent(Event $source): Event
    {
        return Event::create([
            'company_id' => $source->company_id,
            'name' => $this->copyName($source->name),
            'description' => $source->description,
            'venue' => $source->venue,
            // A copy never inherits the original date — the organiser sets a new
            // one, which also keeps it clear of the "no past dates" publish rule.
            'starts_at' => null,
            'capacity' => $source->capacity,
            // Always a draft: unpublished and not cancelled.
            'is_published' => false,
            'cancelled_at' => null,
            'location_mode' => $source->location_mode,
            'address' => $source->address,
            'latitude' => $source->latitude,
            'longitude' => $source->longitude,
            'primary_colour' => $source->primary_colour,
            'logo_path' => $this->duplicateImage($source->logo_path, self::LOGO_DIRECTORY),
            'poster_path' => $this->duplicateImage($source->poster_path, self::POSTER_DIRECTORY),
            'ticket_instructions' => $source->ticket_instructions,
            // Legacy inline top/bottom sponsor fields (kept for older events).
            'sponsor_top_path' => $this->duplicateImage($source->sponsor_top_path, self::SPONSOR_DIRECTORY),
            'sponsor_top_name' => $source->sponsor_top_name,
            'sponsor_top_website' => $source->sponsor_top_website,
            'sponsor_top_bio' => $source->sponsor_top_bio,
            'sponsor_top_on_ticket' => $source->sponsor_top_on_ticket,
            'sponsor_bottom_path' => $this->duplicateImage($source->sponsor_bottom_path, self::SPONSOR_DIRECTORY),
            'sponsor_bottom_name' => $source->sponsor_bottom_name,
            'sponsor_bottom_website' => $source->sponsor_bottom_website,
            'sponsor_bottom_bio' => $source->sponsor_bottom_bio,
            'sponsor_bottom_on_ticket' => $source->sponsor_bottom_on_ticket,
        ]);
    }

    /**
     * Copy every Ticket_Type onto the new Event. Sold/reserved counts are reset
     * to zero: the copy has taken no bookings, so its availability starts full.
     * Price, capacity, mode and sale window are carried over unchanged.
     */
    private function copyTicketTypes(Event $source, Event $copy): void
    {
        foreach ($source->ticketTypes()->get() as $type) {
            /** @var TicketType $type */
            $copy->ticketTypes()->create([
                'name' => $type->name,
                'description' => $type->description,
                'price_minor' => $type->price_minor,
                'capacity' => $type->capacity,
                'capacity_mode' => $type->capacity_mode,
                // The copy has no bookings — start availability full.
                'sold_count' => 0,
                'reserved_count' => 0,
                'sale_starts_at' => $type->sale_starts_at,
                'sale_ends_at' => $type->sale_ends_at,
            ]);
        }
    }

    /**
     * Copy the attendee questions in their display order.
     */
    private function copyQuestions(Event $source, Event $copy): void
    {
        foreach ($source->questions()->get() as $question) {
            /** @var EventQuestion $question */
            $copy->questions()->create([
                'type' => $question->type,
                'label' => $question->label,
                'options' => $question->options,
                'required' => $question->required,
                'position' => $question->position,
            ]);
        }
    }

    /**
     * Copy the repeatable sponsors, duplicating each logo file so the two
     * Events never share an image on disk. A sponsor whose logo file is missing
     * is skipped (it could not render on the copy anyway).
     */
    private function copySponsors(Event $source, Event $copy): void
    {
        foreach ($source->sponsors()->get() as $sponsor) {
            /** @var EventSponsor $sponsor */
            $newPath = $this->duplicateImage($sponsor->image_path, self::SPONSOR_DIRECTORY);

            if ($newPath === null) {
                continue;
            }

            $copy->sponsors()->create([
                'image_path' => $newPath,
                'name' => $sponsor->name,
                'website_url' => $sponsor->website_url,
                'bio' => $sponsor->bio,
                'on_ticket' => $sponsor->on_ticket,
                'sort_order' => $sponsor->sort_order,
            ]);
        }
    }

    /**
     * Duplicate a stored image on the `public` disk into `$directory`, returning
     * the new relative path. Returns null when the input is null/blank or the
     * source file does not exist, so a missing image never aborts the copy — the
     * corresponding field is simply left empty on the new Event.
     */
    private function duplicateImage(?string $path, string $directory): ?string
    {
        if ($path === null || trim($path) === '') {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'png';
        $newPath = $directory.'/'.str()->uuid().'.'.$extension;

        $disk->put($newPath, $disk->get($path));

        return $newPath;
    }

    /**
     * Derive the copy's name. Appends " (copy)" unless the source name already
     * ends that way, in which case a numeric suffix keeps successive copies
     * distinct — "Gala (copy)" → "Gala (copy 2)" → "Gala (copy 3)". Names are
     * clamped to the column's 255-char limit.
     */
    private function copyName(string $name): string
    {
        $name = trim($name);

        if (! str_ends_with($name, '(copy)') && ! preg_match('/\(copy \d+\)$/', $name)) {
            return $this->clamp($name.' (copy)');
        }

        if (str_ends_with($name, '(copy)')) {
            return $this->clamp(substr($name, 0, -strlen('(copy)')).'(copy 2)');
        }

        // Ends with "(copy N)" — bump N.
        return $this->clamp((string) preg_replace_callback(
            '/\(copy (\d+)\)$/',
            fn (array $m): string => '(copy '.((int) $m[1] + 1).')',
            $name,
        ));
    }

    /**
     * Clamp a name to the events.name column limit (255 chars).
     */
    private function clamp(string $name): string
    {
        return mb_substr(trim($name), 0, 255);
    }
}
