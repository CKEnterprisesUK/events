# Implementation Plan: Event Experience Polish

## Overview

This plan implements the follow-on polish of the single-event experience as an additive, non-breaking change to the existing multi-tenant Laravel platform. Work is sequenced so shared/foundational pieces land first (migrations + models + factory), then the reservation engine, then readiness/report/geocoding/image services, then controllers, then views, then tests. Each task references the requirements and/or correctness properties it implements.

The stack is PHP/Laravel with Blade views, PHPUnit + Eris for property-based tests (extending `tests/PBT/PbtTestCase`, run against a real MySQL test DB, ≥100 iterations), and Vite building Tailwind CSS only — there is no compiled JS bundle, so Leaflet is loaded from CDN and client behaviour is small vanilla JS in `@push('scripts')`. Verify code with `php -l` for syntax and `php artisan test` for the suites.

## Tasks

- [x] 1. Foundation: schema, models, factory
  - [x] 1.1 Add capacity-mode migration + raw SQL for `ticket_types`
    - Create `database/migrations/*_add_capacity_mode_to_ticket_types.php`: add `capacity_mode` varchar(20) NOT NULL default `capped` after `capacity`, make `capacity` unsigned nullable, and backfill `capacity_mode = 'shared_pool'` where the type's capacity equals its event's non-null capacity else `capped`
    - Create matching `database/sql/020_add_capacity_mode_to_ticket_types.sql` following the exact header/provenance/session-guard/`migrations` ledger convention of `018`/`019` (same `ALTER TABLE`, backfill `UPDATE`, single ledger `INSERT`)
    - _Requirements: 2.1, 2.2, 2.3, 2.9_

  - [x] 1.2 Add location migration + raw SQL for `events`
    - Create `database/migrations/*_add_location_to_events.php`: add `location_mode` varchar(20) NOT NULL default `in_person` after `venue`, `address` text null, `latitude`/`longitude` decimal(10,7) null; `poster_path` already exists — no schema change
    - Create matching `database/sql/021_add_location_to_events.sql` following the `018`/`019` convention (header/provenance/session guards + single ledger `INSERT`)
    - _Requirements: 4.1, 4.2_

  - [x] 1.3 Extend `TicketType` model for capacity mode
    - Add `MODE_CAPPED`/`MODE_SHARED_POOL`/`MODES` consts, add `capacity_mode` to `$fillable`, keep `capacity` integer cast (now nullable), default `$attributes['capacity_mode'] = capped`
    - Add `isCapped()`/`isSharedPool()`, make `availableQuantity()` mode-aware (capped identity; shared-pool uses `event->overallRemaining()` or `PHP_INT_MAX` sentinel), add `availabilityFor(?int $eventRemaining): ?int`
    - _Requirements: 2.1, 2.5, 2.6, 7.2_

  - [x] 1.4 Extend `Event` model for location + overall remaining + publish blocker
    - Add `LOCATION_IN_PERSON`/`LOCATION_ONLINE`/`LOCATION_MODES` consts, add `location_mode`/`address`/`latitude`/`longitude` to `$fillable`, `decimal:7` casts for lat/lng, default `$attributes['location_mode'] = in_person`
    - Add `isInPerson()`/`isOnline()`/`hasCoordinates()`, add `overallRemaining(): ?int`, and add the `shared_pool_capacity` blocker to `publishBlockers()`
    - _Requirements: 3.1, 3.4, 4.1, 2.4, 2.6_

  - [x] 1.5 Update `TicketTypeFactory`
    - Default `capacity_mode => 'capped'` explicitly and add a `sharedPool()` state (nullable capacity) for the new shared-pool property tests; keep existing capped-default behaviour so current capacity PBTs stay green
    - _Requirements: 2.1, 2.3_

- [x] 2. Reservation engine: mode-aware per-type branch
  - [x] 2.1 Make `CapacityReservationService::reserve()` per-type branch mode-aware
    - Enforce the per-type ceiling (`capacity - sold - reserved`) only for capped types; skip the per-type ceiling for shared-pool types; leave the event-overall check unchanged so it applies to all types when `event.capacity` is non-null
    - Preserve the `SELECT ... FOR UPDATE` lock order (event first, ticket types ascending id), all-or-nothing semantics, and no-oversell; verify `release()`/`commit()`/`releaseSold()` only move counts and require no change
    - _Requirements: 2.4, 2.5, 2.6, 2.7, 2.8, 7.1_

  - [x]* 2.2 Write property test: capped type never oversells
    - **Property 1: Capped type never oversells its own capacity**
    - **Validates: Requirements 2.5, 2.7, 7.2**

  - [x]* 2.3 Write property test: event-overall ceiling across all modes
    - **Property 2: Event-overall ceiling holds across all modes** (mixed capped + shared-pool types under a finite event capacity)
    - **Validates: Requirements 2.4, 2.8, 7.1, 7.2**

  - [x]* 2.4 Write property test: shared-pool governed solely by overall ceiling
    - **Property 3: Shared-pool type is governed solely by the overall ceiling** (no per-type ceiling applied; own counts still tracked)
    - **Validates: Requirements 2.3, 2.6, 7.2**

  - [x]* 2.5 Write property test: release/commit/returns preserve identity under both modes
    - **Property 7: Release/commit/returns preserve the availability identity under both modes** (counts never negative)
    - **Validates: Requirements 7.1, 7.2**

- [x] 3. Checkpoint - foundation + engine
  - Ensure all tests pass, ask the user if questions arise.

- [x] 4. Availability, readiness, and report wiring
  - [x]* 4.1 Write property test: availability computation matches capacity mode
    - **Property 4: Availability computation matches the capacity mode** (`availabilityFor()` for capped clamps by event remaining; shared-pool returns event remaining/null)
    - **Validates: Requirements 2.5, 2.6, 7.2**

  - [x]* 4.2 Write property test: migration backfill classifies existing types correctly
    - **Property 5: Migration backfill classifies existing types correctly** (shared_pool iff capacity equalled event non-null capacity, else capped)
    - **Validates: Requirements 2.9**

  - [x] 4.3 Add `shared_pool_capacity` blocking item to `EventReadiness::checklist()`
    - Derive a new blocking `ChecklistItem` (`key: 'shared_pool_capacity'`, satisfied when the blocker is absent) from `publishBlockers()`, placed after `ticket_types`
    - _Requirements: 3.3_

  - [x]* 4.4 Write property test: shared-pool-without-overall-capacity publish blocker
    - **Property 6: Shared-pool-without-overall-capacity publish blocker** (present iff null overall capacity AND a shared-pool type exists; `isPublishable()` false while present)
    - **Validates: Requirements 3.1, 3.2, 3.4**

  - [x] 4.5 Make `EventReportService::perTicketType()` remaining mode-aware
    - Compute event overall remaining once; set `remaining` to null for shared-pool types (view renders `— (shared pool)`) and include `capacity_mode` in each row
    - _Requirements: 2.6_

  - [x]* 4.6 Update `EventReportPerTicketTypeTest` for shared-pool remaining
    - Add a shared-pool case asserting `remaining` is null/"shared" and `capacity_mode` is present
    - _Requirements: 2.6_

- [x] 5. Geocoding service
  - [x] 5.1 Create `GeocodeResult` value object
    - `App\Services\Geocoding\GeocodeResult` — immutable `latitude`, `longitude`, `?displayName`
    - _Requirements: 4.2_

  - [x] 5.2 Create `GeocodingService` (Nominatim wrapper) + config
    - `App\Services\GeocodingService::geocode(string): ?GeocodeResult` — Nominatim via `Http` with required config User-Agent (null when unset), `Cache::remember` by normalised address (cache hit = no HTTP), negative-result caching, ~1 req/s friendliness, returns null on miss/error/timeout
    - Add `config/services.php` `nominatim` block (`base_uri`, `user_agent`, `cache_ttl`, `timeout`)
    - _Requirements: 4.2, 4.4, 4.5_

  - [x]* 5.3 Write property test: geocode caching and identifying User-Agent
    - **Property 9: Geocode caching and identifying User-Agent** (first lookup at most one request with configured UA; subsequent same-address lookups served from cache)
    - **Validates: Requirements 4.4**

  - [x]* 5.4 Write feature/unit test for `GeocodingService`
    - `Http::fake()` + array cache: cached address issues zero second HTTP call, request carries UA header, null/error response yields null, missing UA yields null
    - _Requirements: 4.4, 4.5_

- [x] 6. Shared branding image store
  - [x] 6.1 Extract `BrandingImageStore` and reuse in `BrandingController`
    - Extract `BrandingController`'s image store/delete into a shared `BrandingImageStore` service (store on `public` disk under `branding/posters`, delete previous file) and refactor `BrandingController` to use it, preserving current behaviour
    - _Requirements: 5.1_

- [x] 7. Controllers: ticket-type, event, geocode + poster wiring
  - [x] 7.1 Add `capacity_mode` to `TicketTypeController::validated()` and redirect index to tab
    - Add `capacity_mode` (`Rule::in(MODES)`), make `capacity` required only when capped via `Rule::requiredIf` and null for shared-pool; make `TicketTypeController@index` redirect to the show page's Ticket types tab
    - _Requirements: 2.1, 2.2, 2.3, 6.1_

  - [x] 7.2 Add location + poster fields to `EventController::validated()`
    - Add `location_mode` (`Rule::in(LOCATION_MODES)`), `address`, `latitude`/`longitude` (numeric within range), `poster` (`image` mimes jpeg,png,webp max:4096)
    - _Requirements: 4.1, 4.2, 5.2, 5.3_

  - [x] 7.3 Wire geocode + poster + location into `EventController@store`/`@update`
    - Inject `GeocodingService`; on in-person save geocode when address present and changed, clear map fields when online, flash `geocode_warning` on failure without discarding a manual pin, and do not re-geocode an unchanged address (honour dragged pin); upload poster via `BrandingImageStore`
    - _Requirements: 4.2, 4.3, 4.4, 4.5, 5.1_

  - [x] 7.4 Add view data to `EventController@show`/`@index`
    - `show()` passes location + hero + capacity-mode view data and computes event `overallRemaining()` once; `index()` resolves the hero thumbnail per row
    - _Requirements: 1.4, 1.5, 5.4, 5.5, 5.6_

  - [-]* 7.5 Write feature test: event geocode/location save behaviour
    - In-person save stores lat/lng; failure saves without coordinates and flashes warning; unchanged address does not re-geocode; online save clears map fields
    - _Requirements: 4.2, 4.3, 4.5_

  - [-]* 7.6 Write feature test: poster upload + hero precedence
    - Accepts jpeg/png/webp ≤4 MB, rejects other types/oversize leaving existing poster unchanged; hero resolves per-event over company
    - **Property 10: Hero image precedence**
    - **Validates: Requirements 5.2, 5.3, 5.4, 5.5, 5.6**

  - [-]* 7.7 Write property/feature test: comp issuance bound like a paid reservation
    - **Property 8: Comp issuance is bound by capacity in the same way as a paid reservation** (capped: per-type + overall; shared-pool: overall only; over-request issues nothing)
    - **Validates: Requirements 6.4, 6.5**

- [~] 8. Checkpoint - controllers + geocoding + image
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 9. Views: dashboard tab shell + partials
  - [~] 9.1 Create accessible `_tabs.blade.php` tab shell
    - ARIA `tablist`/`tab`/`tabpanel` markup + vanilla JS in `@push('scripts')` for click + keyboard nav (Left/Right/Home/End, Enter/Space) + `location.hash` deep-linking; no-JS renders all panels visible with the tab strip hidden until JS enables it
    - _Requirements: 1.1, 1.2, 1.3, 1.8_

  - [~] 9.2 Rewrite `dashboard/events/show.blade.php` into the tab shell
    - Page head (title, publish/unpublish, status/errors) + optional hero + `_tabs`; map partials to tabs (Overview, Ticket types, Location, Share & QR, Report, Orders) per design, each panel carrying a visible heading
    - _Requirements: 1.1, 1.4, 1.5_

  - [~] 9.3 Create `_hero.blade.php` partial
    - Per-event poster over company poster precedence, rendered via `Storage::disk('public')->url()`
    - _Requirements: 5.4, 5.5, 5.6_

  - [~] 9.4 Create `_location.blade.php` partial
    - Mode toggle + address field + Leaflet draggable mini-map writing hidden `latitude`/`longitude`; Leaflet CSS/JS from CDN via `@push('head')`/`@push('scripts')`; focusable container with accessible label
    - _Requirements: 4.1, 4.3, 4.9_

  - [~] 9.5 Create `_ticket_types.blade.php` partial
    - Move inline ticket-type management from `ticket-types/index`, posting to existing routes; mode-aware available column (capped per-type remaining; shared-pool event pooled remaining)
    - _Requirements: 6.1, 6.3, 2.6_

  - [~] 9.6 Create `_comp.blade.php` partial
    - Move comp issuance form from `show`, posting to existing route; mode-aware per-row `max` via `availabilityFor($eventRemaining)`
    - _Requirements: 6.2, 6.3, 6.5_

  - [~] 9.7 Create `_orders.blade.php` partial
    - Move recent-orders list (with cancel/refund) from `show` into the Orders tab
    - _Requirements: 1.4_

  - [~] 9.8 Update `dashboard/events/_form.blade.php`
    - Add `location_mode`/`address`/`poster` fields and hidden `latitude`/`longitude`
    - _Requirements: 4.1, 4.2, 5.1, 5.2_

  - [~] 9.9 Update `dashboard/ticket-types/_form.blade.php`
    - Add `capacity_mode` toggle that shows/hides the capacity input (capacity always visible with no JS)
    - _Requirements: 2.1, 2.2, 2.3_

  - [~] 9.10 Update `dashboard/events/index.blade.php`
    - Render the resolved hero as a small banner/thumbnail per row
    - _Requirements: 5.4_

  - [~] 9.11 Update public `events/show.blade.php`
    - In-person with coordinates: read-only Leaflet map (CDN) + "Open in Google Maps" directions deep-link; online: email joining notice; accessible map label
    - _Requirements: 4.6, 4.7, 4.9_

  - [~] 9.12 Update `checkout/success.blade.php`
    - Online events: add the email joining-info notice
    - _Requirements: 4.8_

  - [ ]* 9.13 Write feature test: tabbed manage-event page + inline mgmt
    - Renders 6 tabs with `role="tab"`/`aria-controls`; panels visible without JS; inline ticket-type create/edit and comp issuance post to existing routes; 403 for non-managers, 404 cross-company
    - _Requirements: 1.1, 1.2, 1.3, 1.6, 1.7, 1.8, 6.1, 6.2, 6.3, 6.6, 6.7, 7.3, 7.4_

- [~] 10. Checkpoint - views
  - Ensure all tests pass, ask the user if questions arise.

- [~] 11. Final checkpoint - full suite
  - Ensure all tests pass (reservation PBTs, publish gating, readiness, report, `EventManagementTest`, and the new suites), ask the user if questions arise.
  - Note: PBTs run against the real MySQL test DB and may need `php artisan migrate:fresh --env=testing` before a full run.

## Notes

- Tasks marked with `*` are optional test tasks and can be skipped for a faster MVP; core implementation tasks are never optional.
- Each task references specific requirements (and, where applicable, the design's numbered correctness property) for traceability.
- Checkpoints ensure incremental validation after foundation+engine, controllers+geocoding+image, views, and a final full run.
- Property tests reuse Eris and `tests/PBT/PbtTestCase`, run ≥100 iterations, and carry the `Feature: event-experience-polish, Property N: ...` tag plus a `Validates` annotation.
- `EventController` and `show.blade.php` are each touched by multiple tasks; the dependency graph sequences those into separate waves to avoid same-file conflicts.
- The reservation engine's capped branch is unchanged, so existing capacity PBTs stay green; `TicketTypeFactory` keeps the capped default and adds a `sharedPool()` state.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2", "5.1", "6.1"] },
    { "id": 1, "tasks": ["1.3", "1.4", "1.5", "5.2"] },
    { "id": 2, "tasks": ["2.1", "4.3", "4.5", "5.3", "5.4", "7.1"] },
    { "id": 3, "tasks": ["2.2", "2.3", "2.4", "2.5", "4.1", "4.2", "4.4", "4.6", "7.2"] },
    { "id": 4, "tasks": ["7.3"] },
    { "id": 5, "tasks": ["7.4"] },
    { "id": 6, "tasks": ["7.5", "7.6", "7.7", "9.1", "9.3", "9.4", "9.5", "9.6", "9.7", "9.8", "9.9", "9.10", "9.11", "9.12"] },
    { "id": 7, "tasks": ["9.2"] },
    { "id": 8, "tasks": ["9.13"] }
  ]
}
```
