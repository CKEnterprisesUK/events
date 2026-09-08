<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Event;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Builds and caches the public Company Storefront listing: the Company's
 * PUBLISHED Events, ordered for display. (Requirements 8.2, 5.4)
 *
 * The listing is read on every storefront hit, so it is cached per Company to
 * keep the page cheap. Publishing, unpublishing, or updating an Event changes
 * what the Storefront should show, so those writes invalidate the cache via
 * {@see forget} — the next storefront request rebuilds it from the database.
 *
 * Unpublished Events are never included, so an Event that is unpublished (or a
 * newly created draft) does not leak onto the public listing. (Requirement 5.5)
 *
 * The cached payload is a plain array (not Eloquent models) so it survives
 * serialisation cleanly across any cache store; branding is resolved at render
 * time from the live Company, not cached here.
 */
class StorefrontListing
{
    /**
     * How long a built listing stays cached before it is rebuilt from the
     * database, independent of explicit invalidation on Event writes.
     */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * The published Events to show on the Company's Storefront, soonest first.
     * Served from cache when warm; otherwise built from the database and cached.
     *
     * @return Collection<int, array{id:int, name:string, venue:?string, starts_at:?string, poster_path:?string, sponsors:list<array{path:string, name:?string, website:?string, bio:?string}>}>
     */
    public function forCompany(Company $company): Collection
    {
        $rows = Cache::remember(
            $this->cacheKey($company->getKey()),
            self::CACHE_TTL_SECONDS,
            fn () => $this->build($company),
        );

        return collect($rows);
    }

    /**
     * Invalidate a Company's cached Storefront listing so the next request
     * rebuilds it. Called whenever an Event's publish state or details change.
     */
    public function forget(Company $company): void
    {
        Cache::forget($this->cacheKey($company->getKey()));
    }

    /**
     * Build the listing payload from the database: the Company's published
     * Events only, soonest first, as plain arrays. (Requirements 5.4, 8.2)
     *
     * @return list<array{id:int, name:string, venue:?string, starts_at:?string, poster_path:?string, sponsors:list<array{path:string, name:?string, website:?string, bio:?string}>}>
     */
    private function build(Company $company): array
    {
        // The Company hero is the fallback poster for any Event without its own,
        // so the listing can show a thumbnail per Event. Resolved here (not at
        // render) so the cached payload carries the effective poster directly.
        $companyPoster = $company->poster_path;

        return Event::query()
            // Filter explicitly by Company and bypass the request-scoped tenant
            // global scope so the listing is built correctly whether or not a
            // tenant is resolved for the current request (e.g. dashboard writes
            // invalidate then callers may rebuild outside a storefront request).
            ->withoutGlobalScopes()
            // Sponsors are rendered per-event; eager-load them (also without the
            // tenant scope) so the payload can carry each event's sponsor rows.
            ->with(['sponsors' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('company_id', $company->getKey())
            ->where('is_published', true)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Event $event): array => [
                'id' => $event->getKey(),
                'name' => $event->name,
                'venue' => $event->venue,
                'starts_at' => $event->starts_at?->toIso8601String(),
                // Event poster else the Company hero, so the listing thumbnail
                // is populated even for Events that don't set their own.
                'poster_path' => $this->firstFilled($event->poster_path, $companyPoster),
                // Per-Event sponsors (image + store-page name/website/bio),
                // surfaced in the storefront's combined "Sponsors" strip.
                'sponsors' => $this->sponsorsFor($event),
            ])
            ->all();
    }

    /**
     * The Event's sponsors as plain arrays for the storefront, in display
     * order. The on_ticket flag is a print-time choice and does not affect
     * public store visibility, so every sponsor logo appears on the storefront.
     *
     * @return list<array{path:string, name:?string, website:?string, bio:?string}>
     */
    private function sponsorsFor(Event $event): array
    {
        return $event->sponsors
            ->map(fn ($sponsor): array => [
                'path' => $sponsor->image_path,
                'name' => $sponsor->name,
                'website' => $sponsor->website_url,
                'bio' => $sponsor->bio,
            ])
            ->all();
    }

    private function cacheKey(int|string|null $companyId): string
    {
        return "storefront:listing:{$companyId}";
    }

    /**
     * The first non-blank value among the given candidates, or null. Treats
     * empty strings as "not set" so a blank Event poster falls through to the
     * Company hero.
     */
    private function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
