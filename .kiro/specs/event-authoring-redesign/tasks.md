# Implementation Plan: Event Authoring Redesign

## Overview

This plan implements the event authoring and management redesign for the Laravel 13 / PHP 8.5 ticketing platform. The stack is plain Blade plus vanilla JavaScript with no frontend build step, inline SVG icons, CDN-loaded Leaflet, and Pest/PHPUnit for tests.

The work is ordered so each task builds on the previous one with no large integration jumps: the schema and pure domain model come first (with property-based and regression tests), then the capacity advisory, then the controllers and validation, then the reusable Blade partials, CSS and JS, then wiring into the manage screen and the create wizard, and finally the cross-cutting navigation/sidebar polish and a full verification pass.

The single most consequential change is the semantic redefinition of the ticket sale window: a null `sale_starts_at`/`sale_ends_at` now means "unbounded on that side" (publication is the effective lower bound; the event start is the effective upper bound). This touches `TicketType::isOnSaleAt()`, the checkout gate, the public page, and existing tests — the regression rewrite is called out as its own task so the change is intentional, not accidental.

Property-based testing applies only to the pure-domain slice (Correctness Properties 1–3). Each PBT is tagged `Feature: event-authoring-redesign, Property N: ...` and runs ≥100 iterations. Everything else uses example, feature/HTTP, and migration tests.

## Tasks

- [ ] 1. Schema additions and `TicketType` domain model
  - [x] 1.1 Add the `description` migration and raw SQL files
    - Create migration `database/migrations/2025_XX_XX_000000_add_description_to_ticket_types.php` adding a nullable `text` `description` column after `name` (with a `down()` that drops it).
    - Create `database/sql/036_add_description_to_ticket_types.sql` with the `ALTER TABLE ... ADD COLUMN description` DDL plus the `migrations` ledger insert, following the numbered house style.
    - Create documentation-only `database/sql/037_allow_unlimited_capacity_mode.sql` recording that `capacity_mode` (already `varchar(20)`) now accepts `unlimited`, with no DDL beyond a comment block / no-op.
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

  - [-] 1.2 Extend `TicketType` constants, fillable, and availability helpers
    - Add `MODE_UNLIMITED = 'unlimited'` and extend `MODES` to include it; add `'description'` to `$fillable`.
    - Add `isUnlimited(): bool`; add the unlimited branch to `availabilityFor(?int $eventRemaining): ?int` (returns `null`); update `availableQuantity()` so unlimited returns the non-binding sentinel (`PHP_INT_MAX`).
    - _Requirements: 7.4, 7.6, 9.1, 9.2, Design: "TicketType model changes"_

  - [~] 1.3 Redefine `isOnSaleAt` and add `saleStatusLabel`
    - Rewrite `isOnSaleAt(Carbon $now)` to the effective-interval semantics: null `sale_starts_at` = no lower bound; effective end = `sale_ends_at ?? event?->starts_at`; half-open `[start, end)`.
    - Confirm `isPurchasableAt()` keeps its shape (`event->isPublished() && isOnSaleAt($now)`).
    - Add `saleStatusLabel(Carbon $now): array` returning `['label', 'pill']` for `On sale` / `Scheduled` / `Ended` / `Not on sale`.
    - _Requirements: 8.7, 8.8, Design: "TicketType model changes"_

  - [ ]* 1.4 Property test: effective sale window (Property 1)
    - **Feature: event-authoring-redesign, Property 1: Sale window equals the effective interval**
    - Randomise null/explicit `sale_starts_at` × `sale_ends_at`, event start (null/explicit), and `now` over ≥100 iterations; assert `isOnSaleAt(now)` iff `now ∈ [effectiveStart, effectiveEnd)`.
    - **Validates: Requirements 8.7, 8.8**

  - [ ]* 1.5 Property test: availability reflects the capacity mode (Property 2)
    - **Feature: event-authoring-redesign, Property 2: Availability reflects the capacity mode**
    - Randomise mode, per-type remaining, and `eventRemaining` (non-negative int or null); assert `unlimited → null`, `shared_pool → eventRemaining`, `capped → min(perTypeRemaining, eventRemaining)` never exceeding either bound.
    - **Validates: Requirements 7.2, 7.3, 7.4**

  - [ ]* 1.6 Unit tests for new `TicketType` helpers
    - Cover `isUnlimited()`, and `saleStatusLabel()` across published/draft × window states.
    - _Requirements: 7.4, 8.7, 8.8_

  - [~] 1.7 REGRESSION: rewrite existing `isOnSaleAt` tests to the new semantics
    - Locate the existing tests that assume a null bound means "never on sale" and rewrite them to the new effective-interval semantics, documenting that the behaviour change is intentional.
    - _Requirements: 8.7, 8.8, Design: "Regression for the semantic change"_

- [ ] 2. Capacity advisory for unbounded types
  - [~] 2.1 Extend `CapacityComparison` with `typesUnbounded` and update `state()`
    - Add a `bool $typesUnbounded` constructor arg (default `false`); update `state()` so a null event capacity → `UNLIMITED`, `typesUnbounded` → `EVENT_BINDS`, else the three-way capped-sum comparison; keep `isSane()` true only for `UNLIMITED`/`BALANCED`.
    - _Requirements: 7.6, Design: "CapacityComparison + EventReadiness::capacity()"_

  - [~] 2.2 Update `EventReadiness::capacity()` to detect unbounded types
    - Compute `typesSum` from capped types only and set `typesUnbounded` when any `unlimited` or `shared_pool` type is present.
    - _Requirements: 7.6_

  - [ ]* 2.3 Property test: capacity advisory never understates an unbounded types side (Property 3)
    - **Feature: event-authoring-redesign, Property 3: Capacity advisory never understates an unbounded types side**
    - Randomise event capacity (positive int or null), finite capped sum, and `typesUnbounded`; assert the classification rules and `isSane()` invariant over ≥100 iterations.
    - **Validates: Requirements 7.6**

  - [ ]* 2.4 Unit tests for `CapacityComparison` specific cases
    - Equal caps → `BALANCED`; unbounded types + finite event cap → `EVENT_BINDS`; no event cap → `UNLIMITED`.
    - _Requirements: 7.6_

- [ ] 3. Public sale-state semantics (checkout + event page)
  - [~] 3.1 Update `EventPageController::saleState()` and verify the checkout gate
    - Revise `saleState()` to the effective-interval logic (`start` null = opens at publication, `end = sale_ends_at ?? event?->starts_at`), returning `not_yet` / `ended` / `on_sale`; leave the per-type array shape unchanged.
    - Confirm `CheckoutController` relies on `isPurchasableAt()` so it inherits the new semantics with no further change.
    - _Requirements: 8.7, 8.8, Design: "Public event page array shape"_

  - [ ]* 3.2 Feature test: published null-bound type is on sale end-to-end
    - Assert a published event with a null-bound ticket type reports `on_sale` on the public page and that checkout accepts it.
    - _Requirements: 8.7, 8.8_

- [ ] 4. Ticket-type controller: validation, destroy action, and route
  - [~] 4.1 Update `TicketTypeController` validation for nullable sale bounds and unlimited mode
    - Make `sale_starts_at`/`sale_ends_at` `nullable`; apply `after:sale_starts_at` and `before_or_equal:event.starts_at` only when the explicit datetimes are present; map absent datetimes to `null`.
    - Extend `capacity_mode` to `Rule::in(TicketType::MODES)` (incl. `unlimited`); make `capacity` `requiredIf` mode `capped` and null it for `shared_pool`/`unlimited`; persist `description`.
    - _Requirements: 7.2, 7.3, 7.4, 8.3, 8.4, 8.9, 9.1, Design: "Error Handling"_

  - [~] 4.2 Add the `destroy` action and route with the last-type-on-published guard
    - Add `TicketTypeController@destroy` gated on `ACTION_MANAGE_TICKET_TYPES`, refusing (redirect back with error) to drop the last type of a published event; register `DELETE /events/{event}/ticket-types/{ticketType}` as `events.ticket-types.destroy` in the dashboard group.
    - _Requirements: 6.1, 6.2, Design: "New / changed routes", "Ticket-type destroy"_

  - [ ]* 4.3 Feature tests for ticket-type CRUD, gating, and tenant isolation
    - Store with both default checkboxes → persists null bounds; store `unlimited`; update; destroy; destroy refused for the last type on a published event; MAX 50 ceiling; `ACTION_MANAGE_TICKET_TYPES` gate; foreign event/type 404 (tenant isolation).
    - _Requirements: 6.7, 6.9, 7.4, 8.3, 8.4, 8.9, 10.3, 10.5_

- [x] 5. Inline SVG icon component
  - [x] 5.1 Create the `x-icon` anonymous Blade component
    - Create `resources/views/components/icon.blade.php` with the `$paths` icon map from the design, decorative defaults (`aria-hidden="true"`, `focusable="false"`), `stroke="currentColor"`, and `class` attribute merging.
    - _Requirements: 3.3, 3.4, Design: "Inline SVG icon system"_

- [ ] 6. Ticket accordion component
  - [~] 6.1 Build the availability and sales-period controls
    - Create `_availability_control.blade.php` (three `.choice` radios for `capped`/`shared_pool`/`unlimited` + conditional quantity input) and `_sales_period_control.blade.php` (two default-checked checkboxes + conditional, `disabled`-when-checked datetime inputs).
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 8.1, 8.2, 8.3, 8.4, 8.5, 8.6_

  - [~] 6.2 Build the accordion row and list partials with server-derived summaries
    - Create `_ticket_accordion_row.blade.php` (summary button with name/price/remaining/status pill/chevron + inline edit form reusing the two controls, save/cancel, and delete for existing rows) and `_ticket_accordion.blade.php` (list wrapper, "Add ticket type" control, hidden new-row `<template>`).
    - Derive price ("Free" for £0), remaining/total via `availabilityFor`, and the status pill via `saleStatusLabel`.
    - _Requirements: 6.1, 6.2, 6.3, 6.6, 6.9, 6.10, 7.6, 10.5_

  - [~] 6.3 Add accordion + control JavaScript and CSS
    - Add the accordion JS (single-open toggle, click-anywhere summary, cancel-resets-and-collapses, add-clones-template-and-focuses) and the small availability/sales JS (show/hide + enable/disable inputs); add accordion/two-column-grid CSS to `app.css` using existing tokens.
    - _Requirements: 6.4, 6.5, 6.8, 6.10, 6.11, 10.2, 10.6_

  - [~] 6.4 Wire the accordion into the Tickets manage screen
    - Replace `_ticket_types.blade.php` usage in `events/tickets.blade.php` with `_ticket_accordion`, passing the event, existing types, and store/update/destroy routes.
    - _Requirements: 6.1, 6.7, 6.8, 10.1_

  - [ ]* 6.5 Feature test for the tickets manage screen rendering
    - Assert the accordion renders existing types with correct summary strings and that the Add control and per-row forms point at the right routes.
    - _Requirements: 6.3, 6.9_

- [ ] 7. Multi-step create wizard
  - [~] 7.1 Create `EventWizardController` and register wizard routes
    - Create `EventWizardController` (`start`, `store`, `step`, `save` + `stepsFor`/`nextStep`/`prevStep`/`gate` helpers), all gated `ACTION_MANAGE_EVENTS`; `store` creates the tenant-scoped draft (name + description) and advances to `when`.
    - Register `events.wizard.store`, `events.wizard.step`, `events.wizard.save`; keep `events.create` pointing at `start`; leave the legacy `events.store` registered.
    - _Requirements: 4.1, 4.2, 4.13, 4.15, Design: "New / changed routes", "Server-driven wizard rationale"_

  - [~] 7.2 Build the wizard layout and per-step views with contextual help
    - Create the wizard shell (`.wizard-steps` progress, `.wizard-panel`, `.wizard-help`, `.wizard-nav` with Back/Skip/Continue-Finish) and the step views: basics, when (`starts_at`), venue (reuse `_location.blade.php` + online option), tickets (reuse `_ticket_accordion`), branding (header image + logo choice), sponsors (reuse sponsor fields).
    - Include contextual help content on each step.
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.12, 10.1_

  - [~] 7.3 Implement per-step persistence, progressive validation, and navigation
    - In `save`, dispatch per step to the reused validators/logic (event update, location+geocode, ticket-type store/update, branding, sponsor store); validate before advancing and redirect back with errors on failure; support Skip on optional steps, Back to previous steps, and Finish → `events.show`.
    - Gate branding/sponsors steps behind `settings` (403/skip when absent); `abort(404)` on invalid step slugs.
    - _Requirements: 4.8, 4.9, 4.10, 4.11, 4.13, 4.14, Design: "Per-step persistence"_

  - [ ]* 7.4 Feature tests for the wizard flow, gating, and tenant isolation
    - Each step GET renders (200) and is gated `ACTION_MANAGE_EVENTS`; `store` creates a tenant-scoped draft; step saves persist and advance; failed validation stays on the step with errors; branding/sponsors 403 without `settings`; Finish lands on `events.show`; foreign event id 404s at every step.
    - _Requirements: 4.9, 4.10, 4.13, 4.14, 4.15, 10.3, 10.4_

- [ ] 8. Publish action on the manage top bar
  - [~] 8.1 Create `_manage_topbar` and move publish/unpublish out of `_publish_card`
    - Create `_manage_topbar.blade.php` (title + status pill + Publish/Unpublish + "All events"), compute `$allRequiredMet` once in `layouts/event.blade.php` from `$readiness->items()` and pass it to both the topbar and the card, render the topbar in the `.page-head` row, and reduce `_publish_card.blade.php` to the checklist only.
    - Gate the topbar on `ACTION_MANAGE_EVENTS`; disable Publish when required blockers remain.
    - _Requirements: 5.1, 5.2, 5.3, 5.4, Design: "Publish_Action on the manage top"_

  - [ ]* 8.2 Feature test for the manage top bar publish action
    - Publish respects `publishBlockers()` and flashes `publish_errors`; unpublish works while live; button disabled/gated correctly.
    - _Requirements: 5.2, 5.3, 5.4_

- [ ] 9. Section_Nav SVG icons and collapsible sidebars
  - [~] 9.1 Convert `_nav.blade.php` to SVG icons and add the collapse control
    - Replace the `$links` emoji `icon` values with icon name keys rendered via `<x-icon>`; convert status flags to `<x-icon name="check|cross|dash" />`; add the Section_Nav collapse `<button>` with `aria-expanded`/`aria-controls`/`aria-label` and `panel-left` icon.
    - _Requirements: 2.1, 2.7, 3.1, 3.2, 3.4, 3.5_

  - [~] 9.2 Convert Main_Sidebar emoji to SVG and add its collapse control
    - Replace `layouts/dashboard.blade.php` `nav-ico` emoji spans with `<x-icon>`; add the Main_Sidebar collapse control (button + `panel-left` icon, accessible labels).
    - _Requirements: 2.2, 2.7, 3.2, 3.4, 3.5_

  - [~] 9.3 Add collapse CSS and the persisted-toggle JS
    - Add rail-mode CSS for both sidebars (icons-only, desktop-only media query; mobile keeps the existing drawer); add the `bindCollapse` JS with `localStorage` keys `ck.sidebar.main` and `ck.sidebar.section`, syncing `aria-expanded` and calling Leaflet `invalidateSize` on toggle.
    - _Requirements: 2.3, 2.4, 2.5, 2.6, 2.7, 10.2, 10.6_

- [ ] 10. Whole-row navigation in dashboard tables
  - [~] 10.1 Add stretched-link CSS and apply row markup to events and customers lists
    - Add `.row-nav` / `.row-link::after` / `.row-action` / `:focus-within` CSS to `app.css` using existing tokens; add `row-nav` to rows and `row-link`/`row-action` classes on `events/index.blade.php` and `customers/index.blade.php` so the whole row navigates while nested actions stay clickable.
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 10.2, 10.6_

  - [ ]* 10.2 Feature test for row primary destinations
    - Assert each events/customers row exposes the correct primary-destination anchor.
    - _Requirements: 1.1, 1.2_

- [ ] 11. Final checkpoint and verification
  - [~] 11.1 Consolidate `app.css` additions and run the full verification pass
    - Ensure all new CSS lives in `app.css` using existing tokens with no duplication; run the migration and the full Pest/PHPUnit suite (including the property tests) and fix any failures.
    - _Requirements: 3.3, 10.2, 10.6_

  - [ ]* 11.2 Manual accessibility and interaction checks
    - Verify keyboard operability of the accordion (Enter/Space, single-open, Cancel focus return) and wizard nav; sidebar collapse persistence across reloads and the desktop icon-only rail; mobile drawer; whole-row hover/focus-visible with nested links still clickable; Leaflet renders/resizes in the venue step and after a sidebar collapse.
    - _Requirements: 2.5, 2.6, 6.11, 10.6_

  - [~] 11.3 Checkpoint - Ensure all tests pass
    - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional (tests and manual-only verification) and can be skipped for a faster MVP; core implementation tasks are never optional.
- Each task references specific requirement sub-clauses (and design sections) for traceability.
- Property tests (1.4, 1.5, 2.3) validate the three universal Correctness Properties; each is tagged `Feature: event-authoring-redesign, Property N: ...` and runs ≥100 iterations.
- Task 1.7 is an explicit regression rewrite of the existing `isOnSaleAt` tests so the null-bound semantic change is intentional, not an accidental break.
- Tenant scoping and Gate authorizations (`ACTION_MANAGE_EVENTS`, `ACTION_MANAGE_TICKET_TYPES`, `settings`) are preserved and covered by gating/isolation tests (4.3, 4.4, 7.4, 8.2).
- Ordering keeps schema/model/domain first, then services, controllers/routes, partials/CSS/JS, screen wiring, and feature tests, so each task builds on prior ones.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "5.1"] },
    { "id": 1, "tasks": ["1.2"] },
    { "id": 2, "tasks": ["1.3", "2.1"] },
    { "id": 3, "tasks": ["1.4", "1.5", "1.6", "1.7", "2.2", "6.1"] },
    { "id": 4, "tasks": ["2.3", "2.4", "3.1", "6.2"] },
    { "id": 5, "tasks": ["3.2", "4.1", "6.3"] },
    { "id": 6, "tasks": ["4.2", "6.4"] },
    { "id": 7, "tasks": ["4.3", "6.5", "7.1", "8.1", "9.1"] },
    { "id": 8, "tasks": ["7.2", "8.2", "9.2", "10.1"] },
    { "id": 9, "tasks": ["7.3", "9.3", "10.2"] },
    { "id": 10, "tasks": ["7.4", "11.1"] },
    { "id": 11, "tasks": ["11.2"] }
  ]
}
```
