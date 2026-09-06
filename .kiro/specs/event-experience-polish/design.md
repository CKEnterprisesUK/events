# Design Document

## Overview

This feature is a follow-on polish of the single-event experience on the existing multi-tenant Laravel event-ticketing platform. It is **additive and non-breaking** with respect to the platform's authorization, tenancy, and accounting foundations, and it preserves `CapacityReservationService` as the sole enforcement point for capacity and its no-oversell guarantees.

Six changes are in scope:

1. **Tabbed manage-event page** — `dashboard/events/{event}` show is restructured into an accessible tab component (Overview, Ticket types, Location, Share & QR, Report, Orders) with vanilla-JS switching that degrades to a scrollable page with no JS.
2. **Per-ticket-type capacity mode** — each `TicketType` gains a `capacity_mode` of `capped` or `shared_pool`. Capped types keep their own per-type ceiling; shared-pool types draw only from the Event's overall capacity. The reservation engine's per-type branch becomes mode-aware; the event-overall ceiling is unchanged.
3. **Publish gating for shared-pool without overall capacity** — a new `shared_pool_capacity` blocker in `Event::publishBlockers()` surfaced through the readiness checklist.
4. **Event location mode + geocoding** — each `Event` gains a `location_mode` (`in_person`/`online`), `address`, and `latitude`/`longitude`. A new `GeocodingService` wraps OpenStreetMap Nominatim (identifying User-Agent, cache, rate-limit friendliness). Leaflet renders a draggable dashboard mini-map and a read-only public map with an "Open in Google Maps" link. Online events omit the map and communicate joining info by email.
5. **Hero image** — wires up the already-existing (but unused) `events.poster_path` / `companies.poster_path` columns via the exact `BrandingController` upload/validation/storage approach, with per-event override of company-level.
6. **Inline ticket / comp management** — folds the separate nested ticket-types page and comp issuance into the Ticket types tab using inline/modal editing, posting to the **existing** controllers, routes, and validation.

The design follows the platform's established conventions:

- **Authorization** goes through the existing gates. Event-management surfaces are gated on `ACTION_MANAGE_EVENTS`; ticket-type surfaces on `ACTION_MANAGE_TICKET_TYPES`; comp issuance on `ACTION_ISSUE_COMP`; reporting on `ACTION_VIEW_REPORTS`. The `Gate::before` Super_Admin bypass and the Owner union-of-actions behaviour are inherited unchanged.
- **Tenant scoping** is inherited from the `dashboard.tenant` middleware group and the global `company_id` scope on `Event`, `TicketType`, `Order`, and `Ticket` (`BelongsToCompany`). Any route that route-model-binds an `Event`/`TicketType` under `/dashboard` therefore resolves cross-company rows as 404 with no extra code.
- **Money** stays in integer minor-currency units end to end.
- **Views** are Blade. The dashboard extends `layouts.dashboard`; the public page extends `layouts.app`. Client behaviour is small vanilla JS in `@push('scripts')` — the repo has **no compiled JS bundle** (Vite builds only Tailwind CSS; there is no `resources/js` app entry and no `@vite` directive in the dashboard layout). Assets are referenced with `asset(...)`. Consequently Leaflet is loaded from CDN `<link>`/`<script>` via `@push('head')`/`@push('scripts')`, matching the existing "plain script tag" pattern.
- **Schema changes ship twice**: a Laravel migration **and** a matching hand-maintained numbered raw-SQL file for phpMyAdmin (`database/sql/NNN_*.sql`), following the header/provenance format of `018_add_poster_to_companies.sql` / `019_add_poster_to_events.sql`.

Code examples are PHP (Laravel) and Blade, matching the existing codebase.

## Architecture

### Request surfaces

No new event-management routes are added for tab display (Requirement 1.5) — the tabbed page is served entirely by the existing `EventController@show`. Inline ticket-type and comp editing post to the **existing** endpoints (Requirement 6.3). Location, hero image, and capacity-mode are extra fields on the **existing** event/ticket-type create/update requests.

```
GET  /dashboard/events                         EventController@index          (ACTION_MANAGE_EVENTS)        [modified: hero thumbnail]
GET  /dashboard/events/{event}                 EventController@show           (ACTION_MANAGE_EVENTS)        [modified: tabs + location/hero data]
POST /dashboard/events                          EventController@store          (ACTION_MANAGE_EVENTS)        [modified: location/poster + geocode]
PUT  /dashboard/events/{event}                 EventController@update         (ACTION_MANAGE_EVENTS)        [modified: location/poster + geocode]
POST /dashboard/events/{event}/publish         EventController@publish        (ACTION_MANAGE_EVENTS)        [unchanged: reuses publishBlockers()]

GET  /dashboard/events/{event}/ticket-types    TicketTypeController@index     (ACTION_MANAGE_TICKET_TYPES)  [kept, redirects to tab — see below]
POST /dashboard/events/{event}/ticket-types    TicketTypeController@store     (ACTION_MANAGE_TICKET_TYPES)  [modified: capacity_mode]
PUT  .../ticket-types/{ticketType}             TicketTypeController@update    (ACTION_MANAGE_TICKET_TYPES)  [modified: capacity_mode]

POST /dashboard/events/{event}/comp            OrderController@issueComp      (ACTION_ISSUE_COMP)           [unchanged]
```

`TicketTypeController@index` (the standalone nested page) is **kept working but redirected** to the show page's Ticket types tab (`redirect()->route('dashboard.events.show', $event) . '#tab-ticket-types'` via a fragment; simplest is a 302 to the show route). Keeping the route avoids breaking bookmarks/links (e.g. the current `_summary`/comp empty-state "Add ticket type" links) while the management UI moves into the tab. All store/update endpoints stay exactly as they are.

All dashboard surfaces live inside the existing `['auth','company.active','session.timeout','dashboard.tenant']` group with the `dashboard.` name prefix, inheriting auth, active-company enforcement, idle timeout, and tenant binding. The public surfaces (`EventPageController@show`, `checkout.success`) live under `ResolveTenant`.

### Component map

```
routes/web.php
  └─ dashboard group
       ├─ events.index    → EventController@index      (hero thumbnail in listing)
       ├─ events.show      → EventController@show        (tabbed; adds location + hero + capacity-mode view data)
       ├─ events.store     → EventController@store       (geocode on in_person save)
       ├─ events.update    → EventController@update      (geocode on in_person address change)
       └─ events.ticket-types.index → TicketTypeController@index (redirect to show tab)

app/Models/TicketType.php   [modified]
  ├─ const MODE_CAPPED='capped', MODE_SHARED_POOL='shared_pool', MODES=[...]
  ├─ fillable += capacity_mode ; casts capacity_mode string, capacity nullable
  ├─ isCapped(): bool / isSharedPool(): bool
  ├─ availableQuantity(): int                 [mode-aware]
  └─ availabilityFor(?int $eventRemaining): int|null   [view/report helper]

app/Models/Event.php        [modified]
  ├─ const LOCATION_IN_PERSON='in_person', LOCATION_ONLINE='online', LOCATION_MODES=[...]
  ├─ fillable += location_mode,address,latitude,longitude (poster_path already present)
  ├─ casts location fields ; latitude/longitude 'decimal:7'
  ├─ isOnline(): bool / isInPerson(): bool / hasCoordinates(): bool
  ├─ overallRemaining(): ?int                 [event capacity − sum(sold+reserved); null=unlimited]
  └─ publishBlockers(): array                 [+ shared_pool_capacity blocker]

app/Services/CapacityReservationService.php   [modified]
  └─ reserve()                                 [per-type branch is mode-aware; release/commit/releaseSold unchanged]

app/Services/EventReadiness.php                [modified]
  └─ checklist()                               [surfaces shared_pool_capacity blocker as blocking item]

app/Services/EventReportService.php            [modified]
  └─ perTicketType()                           [remaining is null/'shared' for shared_pool]

app/Services/GeocodingService.php              [new] Nominatim wrapper (Http + Cache)
app/Services/Geocoding/GeocodeResult.php       [new] value object (lat, lng, displayName|null)

app/Http/Controllers/EventController.php        [modified] validated()/store()/update()/show()/index()
app/Http/Controllers/TicketTypeController.php    [modified] validated() (capacity_mode)

config/services.php                             [modified] 'nominatim' block
config/cache.php / config/filesystems.php       [unchanged] (reuse existing store + public disk)

resources/views/dashboard/events/show.blade.php     [rewritten as tab shell]
resources/views/dashboard/events/_tabs.blade.php    [new] ARIA tablist
resources/views/dashboard/events/_hero.blade.php     [new] hero header
resources/views/dashboard/events/_location.blade.php  [new] location form + mini-map
resources/views/dashboard/events/_ticket_types.blade.php [new] inline ticket-type mgmt (moved from ticket-types/index)
resources/views/dashboard/events/_comp.blade.php     [new] comp issuance (moved from show)
resources/views/dashboard/events/_orders.blade.php   [new] recent orders (moved from show)
resources/views/dashboard/events/_form.blade.php     [modified] + location_mode/address/poster fields
resources/views/dashboard/events/index.blade.php     [modified] hero thumbnail
resources/views/dashboard/ticket-types/_form.blade.php [modified] capacity_mode toggle
resources/views/dashboard/ticket-types/index.blade.php [thin: kept or replaced by redirect]
resources/views/events/show.blade.php                [modified] public map / online notice
resources/views/checkout/success.blade.php           [modified] online joining-info notice

database/migrations/*_add_capacity_mode_to_ticket_types.php   [new]
database/migrations/*_add_location_to_events.php               [new]
database/sql/020_add_capacity_mode_to_ticket_types.sql          [new]
database/sql/021_add_location_to_events.sql                     [new]
```

## Components and Interfaces

### 1. Tabbed manage-event page (Requirement 1)

`show.blade.php` becomes a shell: the page head (title, publish/unpublish controls, status/errors), an optional hero header, and a tab component. Each existing partial is placed into a tabpanel; **no controller data changes** beyond the added location/hero/capacity-mode fields already loaded on the Event.

Tab → content mapping (Requirement 1.4):

| Tab          | Content                                                                 |
|--------------|-------------------------------------------------------------------------|
| Overview     | `_summary` (stats) + `_readiness` (checklist) + `_capacity` (explainer) + event-details edit form (`_form`) |
| Ticket types | `_ticket_types` (inline list/create/edit) + `_comp` (comp issuance)      |
| Location     | `_location` (location-mode form + Leaflet mini-map)                      |
| Share & QR   | `_share`                                                                 |
| Report       | `_summary` figures + link to the dedicated `events.report` page          |
| Orders       | `_orders` (recent orders with cancel/refund)                             |

**Accessibility & progressive enhancement (Requirements 1.2, 1.3, 1.8):**

The tab strip is an ARIA `tablist`; each control is a `<button role="tab" aria-controls="panel-x" aria-selected>`; each panel is `role="tabpanel"` with `aria-labelledby`. A small vanilla-JS controller (in `@push('scripts')`) implements:

- click and keyboard nav (Left/Right/Home/End move focus and selection; Enter/Space activate), following the WAI-ARIA tabs pattern.
- setting `hidden` on non-active panels and `aria-selected`/`tabindex` on tabs.
- deep-linking: on load it reads `location.hash` (e.g. `#tab-location`) to pick the active tab, and updates the hash on switch, so cross-tab links and the ticket-types redirect land on the right tab.

**No-JS fallback:** the panels are rendered **without** the `hidden` attribute server-side; the JS adds `hidden` to inactive panels on init. With JS disabled, every panel stays visible and reachable (Requirement 1.3), and each panel carries a visible `<h2>` heading so the page reads as a normal long page. The tab strip itself is marked `hidden` until JS enables it (or rendered as in-page anchor links that jump to each `<section>`), so no-JS users are not shown dead controls.

```blade
{{-- resources/views/dashboard/events/_tabs.blade.php --}}
<div class="tabs" data-tabs>
  <div class="tablist" role="tablist" aria-label="Manage event sections">
    @foreach ($tabs as $key => $label)
      <button type="button" role="tab" id="tab-{{ $key }}"
              class="tab" data-tab="{{ $key }}"
              aria-controls="panel-{{ $key }}"
              aria-selected="{{ $loop->first ? 'true' : 'false' }}"
              tabindex="{{ $loop->first ? '0' : '-1' }}">{{ $label }}</button>
    @endforeach
  </div>
</div>
```

Each panel:

```blade
<section role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" data-tabpanel="overview" tabindex="0">
  <h2 class="sr-only">Overview</h2>
  @include('dashboard.events._summary', [...])
  @include('dashboard.events._readiness', [...])
  @include('dashboard.events._capacity', [...])
  {{-- event details edit form --}}
</section>
```

Authorization/tenancy are inherited: `show()` already calls `Gate::authorize(ACTION_MANAGE_EVENTS)` (Requirement 1.6) and route-model binding scopes the Event to the Company (Requirement 1.7).

### 2. Per-ticket-type capacity mode (Requirement 2)

#### Model

```php
// app/Models/TicketType.php
public const MODE_CAPPED = 'capped';
public const MODE_SHARED_POOL = 'shared_pool';
/** @var list<string> */
public const MODES = [self::MODE_CAPPED, self::MODE_SHARED_POOL];

protected $attributes = [
    'sold_count' => 0,
    'reserved_count' => 0,
    'capacity_mode' => self::MODE_CAPPED,   // new rows default to capped
];

// fillable += 'capacity_mode'; casts: 'capacity_mode' => 'string'
// capacity stays 'integer' cast but is now nullable at the DB level (shared_pool may omit it).

public function isCapped(): bool      { return $this->capacity_mode === self::MODE_CAPPED; }
public function isSharedPool(): bool  { return $this->capacity_mode === self::MODE_SHARED_POOL; }
```

`availableQuantity()` becomes mode-aware. For a capped type it is the classic identity. For a shared-pool type there is no per-type ceiling, so "available" is the **event overall remaining**; when the event capacity is null (unlimited), there is no finite number and the method returns a large sentinel is avoided — instead a nullable helper is used by views:

```php
/**
 * Remaining available for this type in isolation.
 *  - capped:      capacity - sold_count - reserved_count   (unchanged identity)
 *  - shared_pool: not bounded per-type; callers should use availabilityFor()
 *                 with the event's overall remaining. Returns the event
 *                 remaining if the relation is loaded, else PHP_INT_MAX as a
 *                 non-binding sentinel.
 * (Requirements 2.5, 2.6, 7.2)
 */
public function availableQuantity(): int
{
    if ($this->isCapped()) {
        return (int) $this->capacity - $this->sold_count - $this->reserved_count;
    }
    // shared_pool: governed by event overall remaining.
    $remaining = $this->event?->overallRemaining();
    return $remaining ?? PHP_INT_MAX;
}

/**
 * Availability given a precomputed event overall remaining (null = unlimited).
 * Views/report pass the event remaining once to avoid N+1.
 *  - capped:      min(per-type remaining, eventRemaining ?? per-type remaining)
 *  - shared_pool: eventRemaining  (null => unlimited => return null)
 * (Requirements 2.5, 2.6)
 */
public function availabilityFor(?int $eventRemaining): ?int
{
    if ($this->isCapped()) {
        $perType = (int) $this->capacity - $this->sold_count - $this->reserved_count;
        return $eventRemaining === null ? $perType : min($perType, $eventRemaining);
    }
    return $eventRemaining; // null => unlimited
}
```

```php
// app/Models/Event.php
/**
 * Overall remaining capacity = capacity - sum(sold_count + reserved_count)
 * across all ticket types; null when the Event has no overall capacity
 * (unlimited). (Requirements 2.4, 2.6)
 */
public function overallRemaining(): ?int
{
    if ($this->capacity === null) {
        return null;
    }
    $committed = (int) $this->ticketTypes()->sum(\DB::raw('sold_count + reserved_count'));
    return $this->capacity - $committed;
}
```

#### Reservation engine — the critical change

Only `reserve()`'s per-type availability branch changes. `release()`, `commit()`, and `releaseSold()` **only move counts between buckets** and never test a per-type capacity ceiling, so they need **no change** — they remain correct for both modes because they never read `capacity`. The event-overall check (`eventCommittedAndReserved` vs `event.capacity`) is untouched and continues to apply to **all** types whenever `event.capacity` is non-null. The `SELECT ... FOR UPDATE` lock order (Event first, then ticket types ascending id) and all-or-nothing semantics are preserved.

Exact per-type branch (replaces the current unconditional `$available = capacity - sold - reserved`):

```php
foreach ($quantities as $ticketTypeId => $qty) {
    $ticketType = $ticketTypes[$ticketTypeId];

    // Per-Ticket_Type ceiling applies ONLY to capped types. (Requirements 2.5, 2.7)
    if ($ticketType->isCapped()) {
        $available = (int) $ticketType->capacity
            - $ticketType->sold_count
            - $ticketType->reserved_count;

        if ($qty > $available) {
            throw new InsufficientCapacityException(
                "Insufficient availability for ticket type {$ticketTypeId}: requested {$qty}, {$available} available."
            );
        }
    }
    // shared_pool types skip the per-type ceiling entirely; only the
    // Event-overall check below governs them. (Requirement 2.6)

    $eventRequested += $qty;
}

// Overall Event capacity — UNCHANGED. Applies to every type (capped and
// shared_pool alike) whenever the Event sets a capacity. (Requirements 2.4, 2.8)
if ($lockedEvent->capacity !== null) {
    $eventCommitted = $this->eventCommittedAndReserved($lockedEvent);
    $eventAvailable = $lockedEvent->capacity - $eventCommitted;
    if ($eventRequested > $eventAvailable) {
        throw new InsufficientCapacityException(/* ... */);
    }
}
```

**Edge case — shared_pool with null event capacity = unlimited.** If a shared-pool type is created while the event has no overall capacity, `reserve()` imposes no ceiling at all (per-type skipped, event skipped). This is allowed pre-publish so the manager can configure in any order, but **publishing is blocked** by the new `shared_pool_capacity` blocker (Requirement 3), so an event whose capacity is effectively undefined can never go live and sell.

`sold_count`/`reserved_count` are still tracked per shared-pool type (used by the report's "sold" figure and by `eventCommittedAndReserved`); they are simply not bounded by a per-type `capacity`.

#### View / report usages

- `EventPageController@show` and dashboard views compute the event overall remaining once and pass it to `availabilityFor()` so shared-pool rows show the pooled number (or "unlimited" when the event capacity is null — though a published event can never be in that state due to the blocker).
- `EventReportService::perTicketType()`'s `remaining` becomes mode-aware so it never shows a bogus capacity-based number for shared-pool types:

```php
$eventRemaining = $event->overallRemaining();
// ...
'remaining' => $type->isSharedPool()
    ? null                                   // rendered as '—' / "shared" in the view
    : (int) $type->capacity - $type->sold_count - $type->reserved_count,
'capacity_mode' => $type->capacity_mode,
```

The report view renders `null` remaining for a shared-pool type as `— (shared pool)`.

#### Validation (TicketTypeController)

`validated()` gains `capacity_mode` and makes `capacity` conditional (Requirements 2.2, 2.3):

```php
$validated = $request->validate([
    'name' => ['required', 'string', 'min:1', 'max:100'],
    'price' => ['required', 'numeric', 'min:0', 'max:999999.99', 'decimal:0,2'],
    'capacity_mode' => ['required', Rule::in(TicketType::MODES)],
    // capacity required 1..1,000,000 for capped; nullable for shared_pool.
    'capacity' => [
        Rule::requiredIf(fn () => $request->input('capacity_mode') === TicketType::MODE_CAPPED),
        'nullable', 'integer', 'min:1', 'max:'.self::MAX_CAPACITY,
    ],
    'sale_starts_at' => ['required', 'date'],
    'sale_ends_at' => ['required', 'date', 'after:sale_starts_at'],
], [ /* existing sale-window message */ ]);

return [
    'name' => $validated['name'],
    'price_minor' => (int) round(((float) $validated['price']) * 100),
    'capacity_mode' => $validated['capacity_mode'],
    'capacity' => $validated['capacity_mode'] === TicketType::MODE_SHARED_POOL
        ? null
        : (int) $validated['capacity'],
    'sale_starts_at' => $validated['sale_starts_at'],
    'sale_ends_at' => $validated['sale_ends_at'],
];
```

The `_form.blade.php` gains a mode toggle (radio/select) that shows/hides the capacity input via a tiny bit of JS; with no JS the capacity field is always visible and simply ignored server-side for shared-pool.

### 3. Publish blocker for shared-pool without overall capacity (Requirement 3)

`Event::publishBlockers()` is the single source of truth (enforcement in `EventController@publish`, presentation in `EventReadiness`). Add one blocker:

```php
public function publishBlockers(): array
{
    $blockers = [];

    if ($this->starts_at === null) {
        $blockers['starts_at'] = 'Set a start date and time.';
    }
    if (! $this->ticketTypes()->exists()) {
        $blockers['ticket_types'] = 'Add at least one ticket type.';
    }
    // A shared-pool type draws only from the overall capacity; without one its
    // capacity is undefined. (Requirements 3.1, 3.4)
    if ($this->capacity === null
        && $this->ticketTypes()->where('capacity_mode', TicketType::MODE_SHARED_POOL)->exists()) {
        $blockers['shared_pool_capacity'] =
            'Set an overall event capacity: a shared-pool ticket type needs an overall ceiling to draw from.';
    }

    return $blockers;
}
```

`isPublishable()` and `EventController@publish` inherit the new blocker automatically (Requirement 3.2). `EventReadiness::checklist()` adds a **new blocking** `ChecklistItem` so it appears in the readiness table alongside the existing blockers (Requirement 3.3). Placed after the existing `ticket_types` item and before the advisory `capacity` sanity item:

```php
new ChecklistItem(
    key: 'shared_pool_capacity',
    label: 'Overall capacity for shared pool',
    satisfied: ! isset($blockers['shared_pool_capacity']),
    blocking: true,
),
```

When no shared-pool type exists, or the event has a non-null capacity, the blocker is absent and the checklist item is satisfied (Requirement 3.4).

### 4. Event location mode + geocoding (Requirement 4)

#### Model

```php
// app/Models/Event.php
public const LOCATION_IN_PERSON = 'in_person';
public const LOCATION_ONLINE = 'online';
/** @var list<string> */
public const LOCATION_MODES = [self::LOCATION_IN_PERSON, self::LOCATION_ONLINE];

protected $attributes = [
    'is_published' => false,
    'location_mode' => self::LOCATION_IN_PERSON,
];

// fillable += 'location_mode','address','latitude','longitude'
// casts: 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'
public function isInPerson(): bool    { return $this->location_mode === self::LOCATION_IN_PERSON; }
public function isOnline(): bool      { return $this->location_mode === self::LOCATION_ONLINE; }
public function hasCoordinates(): bool { return $this->latitude !== null && $this->longitude !== null; }
```

#### GeocodingService (Nominatim wrapper)

```php
// app/Services/GeocodingService.php
namespace App\Services;

use App\Services\Geocoding\GeocodeResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeocodingService
{
    /**
     * Resolve an address to coordinates via Nominatim.
     *  - Sends a required identifying User-Agent (Nominatim usage policy).
     *  - Caches by normalised address key so an already-resolved address is
     *    served from cache rather than re-queried. (Requirement 4.4)
     *  - Returns null when the address cannot be located (no match, error,
     *    timeout, or rate-limit) so the caller can save without coordinates
     *    and let the manager set the pin manually. (Requirement 4.5)
     */
    public function geocode(string $address): ?GeocodeResult;

    private function cacheKey(string $address): string; // 'geocode:'.sha1(normalise($address))
    private function normalise(string $address): string; // trim + collapse whitespace + lowercase
}
```

Behaviour:

- **Config-driven.** Reads `config('services.nominatim.base_uri')`, `config('services.nominatim.user_agent')`, `config('services.nominatim.cache_ttl')`, `config('services.nominatim.timeout')`. The User-Agent is **required**; if unset, `geocode()` treats it as a misconfiguration and returns null (no anonymous requests, per Nominatim policy).
- **Cache.** `Cache::remember(cacheKey, ttl, fn)` using the default cache store. A cached hit issues no HTTP request (Requirement 4.4). Negative results (no match) are also cached (short TTL) to avoid hammering on a bad address.
- **Rate-limit friendliness.** Nominatim's public policy is roughly one request per second. The service serializes bursts with a short `Cache::lock('geocode:nominatim', ...)` (or a small `usleep`/last-call-timestamp gate) so two saves in the same second don't fire two immediate lookups; because results are cached, repeat saves of the same address never hit the network at all. Requests set `Http::withHeaders(['User-Agent' => ...])->timeout(...)->retry(0)` and query `/search?format=jsonv2&limit=1&q=...`.
- **Result.** `GeocodeResult(float $latitude, float $longitude, ?string $displayName)` — an immutable value object.

**Explicit Nominatim policy compliance:** identifying User-Agent on every request; at most ~1 req/s; aggressive caching of results (Requirement 4.4). No bulk/automated scraping — geocoding fires only on an in-person save whose address changed.

`config/services.php` gains:

```php
'nominatim' => [
    'base_uri'   => env('NOMINATIM_BASE_URI', 'https://nominatim.openstreetmap.org'),
    'user_agent' => env('NOMINATIM_USER_AGENT'), // REQUIRED: 'EventTicketing/1.0 (ops@example.com)'
    'cache_ttl'  => (int) env('NOMINATIM_CACHE_TTL', 60 * 60 * 24 * 30), // 30 days
    'timeout'    => (int) env('NOMINATIM_TIMEOUT', 5),
],
```

#### Controller wiring

`EventController::validated()` gains location + poster fields:

```php
'location_mode' => ['required', Rule::in(Event::LOCATION_MODES)],
'address'       => ['nullable', 'string', 'max:500'],
// lat/lng come from the hidden inputs the mini-map writes; validated but
// normally overwritten by a successful geocode on address change.
'latitude'      => ['nullable', 'numeric', 'between:-90,90'],
'longitude'     => ['nullable', 'numeric', 'between:-180,180'],
'poster'        => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
```

`store()`/`update()` gain a shared post-validation step (injected `GeocodingService $geocoder`):

```php
// After building $data from validated():
if (($data['location_mode'] ?? null) === Event::LOCATION_IN_PERSON) {
    $address = trim((string) ($data['address'] ?? ''));
    $addressChanged = $isCreate || $address !== (string) $event->address;

    if ($address !== '' && $addressChanged) {
        $result = $geocoder->geocode($address);
        if ($result !== null) {
            $data['latitude']  = $result->latitude;
            $data['longitude'] = $result->longitude;
        } else {
            // Save without coordinates; keep any manual pin the user set.
            $request->session()->flash('geocode_warning',
                'We could not locate that address. Save it, then drag the map pin to set the location manually.');
            // leave lat/lng as submitted (possibly null / manual)
        }
    }
    // If the address is unchanged, keep the stored coordinates (do not re-geocode). (Requirement 4.4)
} else {
    // online: clear map data
    $data['address'] = null;
    $data['latitude'] = null;
    $data['longitude'] = null;
}
```

The geocode runs **synchronously** on save (Requirement 4.2). The manager's dragged pin (hidden lat/lng inputs) is honoured: dragging changes the coordinates without necessarily changing the address, so when the address is unchanged we do not re-geocode and the dragged coordinates persist (Requirement 4.3). The failure path saves without coordinates, flashes a warning, and leaves the pin editable (Requirement 4.5).

The poster upload reuses `BrandingController`'s exact storage helper approach (see §5); to avoid duplicating logic it is extracted into a small shared trait/helper `StoresBrandingImages` (or a `BrandingImageStore` service) used by both controllers, storing on the `public` disk under `branding/posters` and deleting the previous file.

#### Map widget (Leaflet)

Loaded from CDN (matching the repo's plain-`<script>`, no-bundler convention). In `@push('head')`: `leaflet.css`; in `@push('scripts')`: `leaflet.js` then the init script. Both dashboard and public pages use the same OpenStreetMap tile layer with the required attribution.

**Dashboard mini-map (`_location.blade.php`)** — editable, in the Location tab:

- A `<div id="event-map" aria-label="Map — drag the pin to set the event location" role="application" tabindex="0">`.
- Hidden inputs `latitude`/`longitude` inside the event form.
- JS initialises the map centred on the stored coordinates (or a country default when none), adds a **draggable** marker, and on `dragend` writes `marker.getLatLng()` into the hidden inputs. Keyboard: the container is focusable and Leaflet's keyboard pan is enabled; the accessible label describes the map (Requirement 4.9).
- The map + address field are shown only when `location_mode === in_person` (toggled by the mode radio; no-JS shows both, harmless).

**Public map (`events/show.blade.php`)** — read-only, in-person with coordinates only:

```blade
@if ($event->isInPerson() && $event->hasCoordinates())
  <div id="public-map" role="img" aria-label="Map showing the event location"></div>
  <a class="btn btn-outline"
     href="https://www.google.com/maps/dir/?api=1&destination={{ $event->latitude }},{{ $event->longitude }}"
     target="_blank" rel="noopener">Open in Google Maps</a>
@elseif ($event->isOnline())
  <p class="event-online-notice">This is an online event. Joining information will be sent to you by email.</p>
@endif
```

The read-only map has no draggable marker and disables interaction handlers not needed for viewing; it still carries an accessible label (Requirement 4.9). The "Open in Google Maps" link deep-links to directions for the coordinates (Requirement 4.6). Online events omit the map and show the email-joining notice (Requirement 4.7).

#### Online purchase confirmation (Requirement 4.8)

`checkout/success.blade.php` (used for both free and paid returns) adds, when `$event->isOnline()`:

```blade
@if ($event->isOnline())
  <p class="checkout-online-notice">This is an online event — joining information will be sent to <strong>{{ $order->customer_email }}</strong> by email.</p>
@endif
```

`$event` is already passed to the success view by `StripeReturnController@success` and the free-confirm redirect, so no controller change is needed beyond ensuring the event is available (it is).

### 5. Hero image (Requirement 5)

The columns `events.poster_path` and `companies.poster_path` already exist (migration `2024_01_01_001800_add_poster_to_branding`, SQL 018/019) and `Company`/`Event` already list `poster_path` in `$fillable`. **No schema change.** `BrandingController::updateEvent()` and `update()` already handle a `poster` upload (mimes `jpg,jpeg,png,gif,webp`, `max:8192`) — so company-level hero and event branding-override hero are **already wired** through branding. This requirement additionally exposes the hero on the **event form** and renders it, per the stated validation (`jpeg,png,webp`, `max:4096`).

Approach: reuse the exact `BrandingController` storage helper (`storeImage()` → `->store('branding/posters','public')`, delete previous). Extract it into a shared `BrandingImageStore` used by both `BrandingController` and `EventController` so behaviour is identical (same disk, same directory, same old-file cleanup). `EventController::store()/update()`:

```php
if ($request->hasFile('poster')) {
    $data['poster_path'] = $this->images->store($request->file('poster'), 'branding/posters', $event?->poster_path);
}
```

Validation on the event form (Requirement 5.2, 5.3): `['nullable','image','mimes:jpeg,png,webp','max:4096']`. A wrong type or oversize file fails validation with Laravel's message naming the accepted types and size, and the stored poster is left unchanged (the update only sets `poster_path` when a valid file is present). No pixel-dimension rules (Requirement 5.7).

**Resolution / precedence (Requirements 5.4, 5.5, 5.6):** the existing `BrandingResolver` already layers event over company (`hasPoster()`, `posterPath`, used by `events/show.blade.php`). The manage-event hero and events-index thumbnail resolve the effective poster the same way — per-event `poster_path` in preference to `companies.poster_path`, falling back to the company hero when the event has none:

```blade
{{-- _hero.blade.php --}}
@php $poster = $event->poster_path ?? $event->company->poster_path; @endphp
@if ($poster)
  <div class="event-hero"><img src="{{ Storage::disk('public')->url($poster) }}" alt="{{ $event->name }}"></div>
@endif
```

The events index (`index.blade.php`) shows the same resolved poster as a small banner/thumbnail per row (Requirement 5.4). Authorization/tenancy inherited (the event is Company-scoped).

### 6 & 7. Inline ticket / comp management (Requirements 6, 7)

The nested `dashboard/ticket-types/index.blade.php` content moves into `_ticket_types.blade.php` inside the Ticket types tab, and the comp form (currently in `show.blade.php`) moves into `_comp.blade.php` in the same tab. Both **reuse the existing form partials** (`dashboard.ticket-types._form`) and **post to the existing routes** unchanged (Requirement 6.3):

- Create/edit ticket types: `dashboard.events.ticket-types.store` / `.update`.
- Issue comps: `dashboard.events.comp`.

The inline/modal UX mirrors the current `data-toggle` pattern already used in `ticket-types/index.blade.php` and `events/index.blade.php`: a "New ticket type" button reveals an inline create panel; each row's "Edit" reveals an inline edit form row; a small JS toggles `hidden`. This is exactly the existing approach, so it degrades to visible forms with no JS. The comp form keeps its existing zero-quantity-row-disabling JS.

The **available** column in both the ticket-type list and the comp quantities table uses the mode-aware helper: capped rows show the per-type remaining; shared-pool rows show the event pooled remaining (or "shared pool — event capacity" text). The comp form's per-row `max` uses `availabilityFor($eventRemaining)`; for shared-pool rows the max is the event remaining.

Comp issuance already flows through `CompTicketService` → `CapacityReservationService::reserve()`, so it consumes capacity under the same no-oversell path and now naturally honours capacity mode (a shared-pool comp is bound only by the event overall, a capped comp by both) — Requirements 6.4, 6.5. Over-requests raise `InsufficientCapacityException`, surfaced as a validation error, with nothing issued.

Authorization/tenancy (Requirements 6.6, 6.7, 7.3, 7.4) are inherited unchanged: ticket-type writes are gated `ACTION_MANAGE_TICKET_TYPES`, comps `ACTION_ISSUE_COMP`; cross-company events/ticket-types 404 via the tenant scope.

## Data Models

### `ticket_types` (modified)

| Column          | Type                              | Notes                                                            |
|-----------------|-----------------------------------|------------------------------------------------------------------|
| `capacity_mode` | `varchar(20)` NOT NULL default `capped` | `capped` \| `shared_pool` (Requirement 2.1)                 |
| `capacity`      | `int unsigned` **NULL** (was NOT NULL) | required for capped; null allowed for shared_pool (2.2, 2.3) |

**Backfill (Requirement 2.9):** for existing rows, join to the parent event and set
`capacity_mode = 'shared_pool'` where `ticket_types.capacity = events.capacity` (and events.capacity is not null), else `capacity_mode = 'capped'` preserving the current `capacity`. Existing capped rows keep their `capacity`.

Migration:

```php
// database/migrations/xxxx_add_capacity_mode_to_ticket_types.php
public function up(): void
{
    Schema::table('ticket_types', function (Blueprint $t) {
        $t->string('capacity_mode', 20)->default('capped')->after('capacity');
        $t->unsignedInteger('capacity')->nullable()->change(); // requires doctrine/dbal or Laravel 11 native change
    });

    // Backfill: shared_pool where the type capacity equals its event's capacity.
    DB::statement("
        UPDATE ticket_types tt
        JOIN events e ON e.id = tt.event_id
        SET tt.capacity_mode = CASE
            WHEN e.capacity IS NOT NULL AND tt.capacity = e.capacity THEN 'shared_pool'
            ELSE 'capped'
        END
    ");
}

public function down(): void
{
    Schema::table('ticket_types', function (Blueprint $t) {
        $t->dropColumn('capacity_mode');
        // capacity back to NOT NULL only if safe; typically leave nullable on rollback
    });
}
```

### `events` (modified)

| Column          | Type                        | Notes                                            |
|-----------------|-----------------------------|--------------------------------------------------|
| `location_mode` | `varchar(20)` NOT NULL default `in_person` | `in_person` \| `online` (Requirement 4.1) |
| `address`       | `text` NULL                 | free-text address for in-person (4.2)            |
| `latitude`      | `decimal(10,7)` NULL        | ~1 cm precision; range −90..90 (4.2)             |
| `longitude`     | `decimal(10,7)` NULL        | range −180..180 (4.2)                            |

`poster_path` already exists — no change.

```php
// database/migrations/xxxx_add_location_to_events.php
public function up(): void
{
    Schema::table('events', function (Blueprint $t) {
        $t->string('location_mode', 20)->default('in_person')->after('venue');
        $t->text('address')->nullable()->after('location_mode');
        $t->decimal('latitude', 10, 7)->nullable()->after('address');
        $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
    });
}
```

### Numbered raw-SQL files (phpMyAdmin convention)

Two new files following the exact header/provenance format of `018`/`019` (purpose, "Covers changes", provenance regenerate commands, "Applying on prod", the MySQL session `SET` guards, and a single `migrations` ledger `INSERT` recording each migration once).

`database/sql/020_add_capacity_mode_to_ticket_types.sql`:

```sql
-- 020_add_capacity_mode_to_ticket_types.sql  (header/provenance block as in 018/019)
ALTER TABLE `ticket_types`
  ADD COLUMN `capacity_mode` varchar(20) NOT NULL DEFAULT 'capped' AFTER `capacity`,
  MODIFY COLUMN `capacity` int unsigned NULL;

UPDATE `ticket_types` tt
  JOIN `events` e ON e.id = tt.event_id
  SET tt.capacity_mode = CASE
      WHEN e.capacity IS NOT NULL AND tt.capacity = e.capacity THEN 'shared_pool'
      ELSE 'capped' END;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('xxxx_xx_xx_xxxxxx_add_capacity_mode_to_ticket_types', 6);
```

`database/sql/021_add_location_to_events.sql`:

```sql
-- 021_add_location_to_events.sql  (header/provenance block as in 018/019)
ALTER TABLE `events`
  ADD COLUMN `location_mode` varchar(20) NOT NULL DEFAULT 'in_person' AFTER `venue`,
  ADD COLUMN `address` text DEFAULT NULL AFTER `location_mode`,
  ADD COLUMN `latitude` decimal(10,7) DEFAULT NULL AFTER `address`,
  ADD COLUMN `longitude` decimal(10,7) DEFAULT NULL AFTER `latitude`;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('xxxx_xx_xx_xxxxxx_add_location_to_events', 6);
```

(Batch numbers follow the next batch after the current highest; the wrapping `/*!40103 ... */` session guards are copied verbatim from 018/019. Final migration timestamps are filled in when the migrations are created.)

## Error Handling

- **Geocode failure / no match / timeout / rate-limit** → `GeocodingService::geocode()` returns `null`; the event saves without coordinates, a `geocode_warning` is flashed, and the manager can drag the pin manually (Requirement 4.5). No exception propagates to the user.
- **Missing Nominatim User-Agent config** → treated as a geocode failure (returns null); requests are never sent anonymously (policy compliance).
- **Reservation over-request under any mode** → `InsufficientCapacityException`, rejected atomically (nothing reserved), surfaced to checkout/comp callers exactly as today (Requirements 2.7, 2.8, 6.4, 6.5).
- **Publish while blocked** → `EventController@publish` refuses and flashes the blocker messages, including the new shared-pool one; the event stays unpublished (Requirements 3.1, 3.2).
- **Invalid ticket-type input** (missing capacity for capped, bad `capacity_mode`, invalid sale window) → Laravel validation errors on the inline form; nothing persisted.
- **Invalid poster upload** (wrong type / oversize) → validation error naming accepted types and size; stored poster unchanged (Requirement 5.3).
- **Cross-company access** to any modified surface → 404 via the tenant scope (Requirements 1.7, 6.7, 7.4).
- **Unauthorized access** → 403 via the existing gates (Requirements 1.6, 6.6, 7.3).

## Testing Approach

**Dual approach — property tests (Eris, extending `PbtTestCase`, run against the real MySQL test DB, ≥100 iterations) for universal capacity/availability/backfill/blocker invariants, and example/unit/feature tests for specific behaviours (geocode caching, UI wiring, validation).** Every property test carries the `Feature: event-experience-polish, Property N: ...` tag and a `**Validates: Requirements X.Y**` annotation.

**Keeping the existing reservation property tests green.** `NoOversellUnderConcurrencyTest`, `ReservationReleaseRestoresAvailabilityTest`, and the other capacity PBTs currently create ticket types via `TicketTypeFactory` without a `capacity_mode`, and the factory default is `capped`. Because the engine's capped branch is byte-for-byte unchanged, these tests **stay green without modification** — the new `capacity_mode` column defaults to `capped` in both the model `$attributes` and the DB. Decision: **update `TicketTypeFactory`** to (a) default `capacity_mode => 'capped'` explicitly for clarity and (b) add a `sharedPool()` state (nullable capacity) used by the new shared-pool properties. The existing capacity tests are left as-is (they rely on the capped default), keeping their intent explicit that "every type is capped" and preserving green.

New/updated tests:

- **Property (new):** no-oversell for shared-pool types — mirrors `NoOversellUnderConcurrencyTest` but with `sharedPool()` types under a finite event capacity; asserts the event-overall ceiling is never exceeded and no per-type ceiling is falsely applied.
- **Property (new):** mixed-mode event-overall ceiling — an event with both capped and shared-pool types; interleaved reserves never drive `sum(sold+reserved)` above `event.capacity`, and capped types additionally never exceed their own capacity.
- **Property (new):** availability-per-mode — `availabilityFor()` returns the capped identity (clamped by event remaining) for capped, and the event remaining (or null) for shared-pool.
- **Property (new):** migration backfill correctness — for generated events/types, after the backfill rule a type is `shared_pool` iff its capacity equalled its event's non-null capacity, else `capped`.
- **Property (new):** publish blocker — for any event, the `shared_pool_capacity` blocker is present iff (event.capacity is null AND at least one shared-pool type exists); `isPublishable()` is false while present.
- **Feature/unit (new):** `GeocodingService` — a cached address issues zero HTTP calls on the second lookup; every outgoing request carries the configured User-Agent; a null/error response yields `null` (using `Http::fake()` and an array cache store).
- **Feature (new):** in-person save geocodes and stores lat/lng; failure saves without coordinates and flashes the warning; unchanged address does not re-geocode; online save clears map fields.
- **Feature (new):** poster upload accepts jpeg/png/webp ≤4 MB, rejects others, leaves existing poster on rejection; hero renders with per-event precedence over company.
- **Feature (new):** manage-event page renders the six tabs, tab controls have `role="tab"`/`aria-controls`, panels are visible without JS; inline ticket-type create/edit and comp issuance post to the existing routes; 403 for non-managers, 404 cross-company.
- **Existing PBTs** for reservation, publish gate, readiness, and per-event report continue to pass; `EventReportPerTicketTypeTest` gains a shared-pool case asserting `remaining` is null/"shared".

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — essentially, a formal statement about what the system should do. Properties serve as the bridge between human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Capped type never oversells its own capacity

*For any* event and any capped ticket type, across any interleaving of reserve/release/commit operations, the type's `sold_count + reserved_count` never exceeds its `capacity`, and any reserve that would exceed it is rejected in full leaving all counts unchanged.

**Validates: Requirements 2.5, 2.7, 7.2**

### Property 2: Event-overall ceiling holds across all modes

*For any* event with a non-null overall capacity and any mix of capped and shared-pool ticket types, across any interleaving of reservations, the sum of `sold_count + reserved_count` over all of the event's ticket types never exceeds the overall capacity; any reserve that would exceed it is rejected in full leaving all counts unchanged.

**Validates: Requirements 2.4, 2.8, 7.1, 7.2**

### Property 3: Shared-pool type is governed solely by the overall ceiling

*For any* event with a non-null overall capacity and any shared-pool ticket type, a reservation for that type is admitted if and only if the requested quantity fits the event's overall remaining capacity — no separate per-type ceiling is applied — while the type's own `sold_count`/`reserved_count` continue to be tracked.

**Validates: Requirements 2.3, 2.6, 7.2**

### Property 4: Availability computation matches the capacity mode

*For any* ticket type and any event overall remaining value `r` (null = unlimited), `availabilityFor(r)` equals `min(capacity − sold − reserved, r)` when capped (or the per-type remaining when `r` is null), and equals `r` when shared-pool.

**Validates: Requirements 2.5, 2.6, 7.2**

### Property 5: Migration backfill classifies existing types correctly

*For any* pre-existing ticket type, after the backfill its `capacity_mode` is `shared_pool` if and only if its `capacity` equalled its event's non-null overall `capacity`, and is `capped` (preserving its `capacity`) otherwise.

**Validates: Requirements 2.9**

### Property 6: Shared-pool-without-overall-capacity publish blocker

*For any* event, `Event::publishBlockers()` contains the `shared_pool_capacity` blocker if and only if the event's overall capacity is null and at least one of its ticket types is shared-pool; while that blocker is present `isPublishable()` is false and a publish request leaves the event unpublished.

**Validates: Requirements 3.1, 3.2, 3.4**

### Property 7: Release/commit/returns preserve the availability identity under both modes

*For any* sequence of reserve, commit, release, and release-of-sold operations, no `reserved_count` or `sold_count` is ever driven negative, and for a capped type the identity `available = capacity − sold − reserved` continues to hold while for a shared-pool type available equals the event overall remaining.

**Validates: Requirements 7.1, 7.2**

### Property 8: Comp issuance is bound by capacity in the same way as a paid reservation

*For any* event and requested comp quantities, the issuance is admitted if and only if the same quantities would be admitted as a paid reservation under each type's capacity mode (capped: per-type and overall; shared-pool: overall only); an over-request issues nothing and creates no order.

**Validates: Requirements 6.4, 6.5**

### Property 9: Geocode caching and identifying User-Agent

*For any* address, the first geocode may issue at most one Nominatim request carrying the configured identifying User-Agent, and every subsequent geocode of the same (normalised) address returns the cached result without issuing a further request.

**Validates: Requirements 4.4**

### Property 10: Hero image precedence

*For any* event, the effective hero image is the event's own `poster_path` when set, otherwise the company's `poster_path`, otherwise none.

**Validates: Requirements 5.4, 5.5, 5.6**
