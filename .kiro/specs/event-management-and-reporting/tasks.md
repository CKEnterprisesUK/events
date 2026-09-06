# Implementation Plan: Event Management and Reporting

## Overview

This is an additive enhancement to the existing Laravel event-ticketing dashboard. The work is sequenced so shared pieces come first (Event model methods, value objects, services), then controllers and routes, then Blade views, then tests. No schema changes, no CSV export, and the reservation engine (`CapacityReservationService`) is untouched.

Implementation language is PHP (Laravel), matching the existing codebase. The test framework is PHPUnit (via `php artisan test`); property-based tests use the `giorgiosironi/eris` library, extending `tests/PBT/PbtTestCase.php`, at a minimum of 100 iterations each.

## Tasks

- [x] 1. Add publish source-of-truth methods to the Event model
  - In `app/Models/Event.php`, add `publishBlockers(): array` returning an ordered map of blocker key => human message: `starts_at` when `starts_at` is null ("Set a start date and time."), `ticket_types` when `ticketTypes()->exists()` is false ("Add at least one ticket type."). Empty array === publishable.
  - Add `isPublishable(): bool` as sugar over `publishBlockers() === []`.
  - Keep `publish()`/`unpublish()` behaviour untouched; these methods only read state.
  - _Requirements: 1.1, 1.2_

- [-] 1.1 Property test: publish gate is exactly the blocker predicate
  - **Property 1: Publish gate is exactly the blocker predicate**
  - Over generated Events with/without `starts_at` and with/without ticket types, assert `isPublishable()` is true iff both prerequisites are met, and `publishBlockers()` keys are exactly the unmet prerequisites.
  - **Validates: Requirements 1.1, 1.2**

- [ ] 2. Create the readiness value objects
  - [x] 2.1 Create `app/Services/Events/ChecklistItem.php`
    - Immutable final class with public readonly `string $key`, `string $label`, `bool $satisfied`, `bool $blocking`.
    - _Requirements: 2.2, 2.3, 2.4_

  - [x] 2.2 Create `app/Services/Events/EventReadinessReport.php`
    - Immutable value object wrapping `list<ChecklistItem>`; expose the ordered items for the view to render.
    - _Requirements: 2.1_

  - [x] 2.3 Create `app/Services/Events/CapacityComparison.php`
    - Constructor `(?int $eventCapacity, int $typesSum)`. Constants `UNLIMITED`, `EVENT_BINDS`, `TYPES_BIND`, `BALANCED`.
    - `state(): string` — `UNLIMITED` when capacity null, `EVENT_BINDS` when capacity < typesSum, `TYPES_BIND` when capacity > typesSum, `BALANCED` when equal.
    - `isSane(): bool` — advisory only; implement cleanly (remove the design's redundant ternary): unlimited or balanced => true, any mismatch (`EVENT_BINDS`/`TYPES_BIND`) => false. Sanity is informational and never blocks publish.
    - _Requirements: 3.2, 3.3, 3.4_

  - [-] 2.4 Property test: capacity comparison classifies the ceiling correctly
    - **Property 5: Capacity comparison classifies the ceiling correctly**
    - Over generated `(?int capacity, int typesSum)`, assert `state()` matches null/`<`/`>`/`=` classification.
    - **Validates: Requirements 3.2, 3.3**

- [-] 3. Create the EventReadiness service
  - Create `app/Services/EventReadiness.php` (stateless, mirrors `OnboardingChecklist`).
  - `checklist(Event): EventReadinessReport` — builds the ordered items {name, start date, venue, ticket type, capacity sanity}, deriving each `satisfied` flag from the Event state, using `publishBlockers()` as the single source of truth for `starts_at` and `ticket_types`. Mark start-date and ticket-type items `blocking: true`; name, venue, and capacity-sanity `blocking: false`.
  - `capacity(Event): CapacityComparison` — build from `$event->capacity` and `(int) $event->ticketTypes()->sum('capacity')`.
  - Private `filled(?string): bool` helper for name/venue presence.
  - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.2, 3.3_

  - [~] 3.1 Property test: checklist reflects event state
    - **Property 3: Checklist reflects event state**
    - Over generated Events, assert the checklist contains exactly {name, start date, venue, ticket type, capacity sanity} and each `satisfied` flag equals the predicate on the Event.
    - **Validates: Requirements 2.1, 2.2**

  - [~] 3.2 Property test: blocking items match the publish source of truth
    - **Property 4: Blocking checklist items match the publish source of truth**
    - Over generated Events, assert the `blocking` items are exactly {start date, ticket type}, each unmet blocking item corresponds to a `publishBlockers()` key, and venue/capacity-sanity are never blocking.
    - **Validates: Requirements 2.3, 2.4**

  - [~] 3.3 Property test: capacity warnings never block publishing
    - **Property 6: Capacity warnings never block publishing**
    - Over generated Events with both real prerequisites met, assert `publishBlockers()` is empty for every capacity comparison state.
    - **Validates: Requirements 3.4**

  - [~] 3.4 Unit tests for EventReadiness checklist and capacity states
    - Cover checklist item set and satisfied/blocking flags across varied event states; capacity comparison across `<`, `>`, `=`, and null.
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 3.2, 3.3_

- [~] 4. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Create the shared accounting value object
  - Create `app/Services/Reporting/EventReport.php` (immutable final class): `int $confirmedOrders`, `int $ticketsSold`, `int $grossRevenueMinor`, `int $netToCompanyMinor`, `?int $capacity`, `array $perTicketType`, `array $ordersByStatus`, `array $salesByDay`.
  - `utilisation(): float|string` — returns `'unlimited'` when capacity is null or 0, otherwise `round(ticketsSold / capacity * 100, 1)`.
  - _Requirements: 5.4, 6.2_

- [-] 6. Create the EventReportService (single accounting source of truth)
  - Create `app/Services/EventReportService.php`. Define `CONFIRMED_STATUSES = [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED]` (same definition currently in `ReportController`).
  - `for(Event): EventReport` — load the Event's confirmed orders; compute confirmed order count, tickets sold (count of `valid` tickets on confirmed orders), gross revenue (`order_total_minor` sum), net to company (`order_total_minor − application_fee_minor` sum), and pass through `capacity`.
  - Private `perTicketType(Event, array $confirmedIds): array` — per type: sold = valid confirmed tickets of that type; remaining = `capacity − sold_count − reserved_count`; revenue derived from that type's confirmed tickets.
  - Private `ordersByStatus(Event): array` — counts of the Event's orders in paid, reserved, refunded, cancelled, and comp (`free_confirmed`) states.
  - Private `salesByDay(Collection $confirmed, array $confirmedIds): array` — confirmed orders grouped by fulfilment day => tickets + revenue.
  - _Requirements: 5.1, 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 6.5, 6.6_

  - [~] 6.1 Property test: confirmed-order accounting definitions
    - **Property 7: Confirmed-order accounting definitions**
    - Over generated order/ticket populations, assert Tickets_Sold = count of `valid` tickets on `paid`/`free_confirmed` orders, and Net_To_Company = sum of `order_total_minor − application_fee_minor` over confirmed orders.
    - **Validates: Requirements 5.2, 5.3, 6.2, 6.6**

  - [~] 6.2 Property test: capacity utilisation honours unlimited
    - **Property 8: Capacity utilisation honours unlimited**
    - Over generated Events, assert `utilisation()` is `'unlimited'` when capacity is null, otherwise ticketsSold relative to the fixed capacity.
    - **Validates: Requirements 5.4**

  - [~] 6.3 Property test: per-ticket-type breakdown is consistent
    - **Property 9: Per-ticket-type breakdown is consistent**
    - Over generated Events, assert each row's sold = valid confirmed tickets of that type, remaining = `capacity − sold_count − reserved_count`, revenue derived from that type's confirmed tickets.
    - **Validates: Requirements 6.3**

  - [~] 6.4 Property test: orders-by-status breakdown is exhaustive
    - **Property 10: Orders-by-status breakdown is exhaustive**
    - Over generated Events, assert each bucket (paid, reserved, refunded, cancelled, comp) equals the count of the Event's orders in that status.
    - **Validates: Requirements 6.4**

  - [~] 6.5 Property test: sales-over-time matches grouped sums
    - **Property 11: Sales-over-time matches grouped sums**
    - Over generated confirmed orders, assert each day's tickets and revenue equal the sums over confirmed orders fulfilled that day.
    - **Validates: Requirements 6.5**

  - [~] 6.6 Unit tests for EventReportService accounting
    - Tickets-sold counts only valid tickets on confirmed orders; net = order_total − application_fee; utilisation `'unlimited'` for null capacity; per-type/by-status/by-day aggregations.
    - _Requirements: 5.2, 5.3, 5.4, 6.2, 6.3, 6.4, 6.5_

- [ ] 7. Refactor ReportController to delegate per-event accounting
  - Refactor `ReportController::perEventBreakdown` (or equivalent) to derive per-event figures from `EventReportService` so the company-wide report and per-event report cannot diverge. Keep the company-wide `index()` output shape and behaviour identical — existing `AccountantReportingTest` must still pass unchanged.
  - _Requirements: 6.6_

  - [~] 7.1 Property test: per-event figures agree with the company report
    - **Property 12: Per-event figures agree with the company report**
    - Over generated Events, assert `EventReportService::for()` figures equal `ReportController`'s per-event breakdown figures for the same Event.
    - **Validates: Requirements 6.6**

- [~] 8. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise. Confirm the existing `AccountantReportingTest` still passes after the refactor.

- [ ] 9. Update EventController for publish gating, QR, and show view data
  - [~] 9.1 Add blocker validation to `EventController::publish()`
    - Read `$event->publishBlockers()`; when non-empty, redirect back to `dashboard.events.show` with `publish_errors` (an array of the blocker messages) and leave the Event unpublished. When empty, publish as today and forget the storefront listing cache.
    - _Requirements: 1.1, 1.2_

  - [~] 9.2 Add `EventController::qr(Event $event, QrService $qr): Response`
    - Authorize `ACTION_MANAGE_EVENTS`. Build the public URL via `route('event.page', ['companySlug' => $event->company->slug, 'event' => $event->id])`.
    - Call `$qr->png($url, 512)` inside a `try`; catch `\Throwable` and `abort(500, ...)` so a missing GD extension returns an error, never PNG bytes.
    - On success return `response($png, 200, [...])` with `Content-Type: image/png` and an attachment `Content-Disposition`. QR is produced for both draft and published events.
    - _Requirements: 4.3, 4.5, 4.7_

  - [~] 9.3 Extend `EventController::show()` with readiness, capacity, public URL, and report view data
    - Resolve `EventReadiness` (checklist + capacity), the public URL, and `EventReportService::for($event)`; pass them to the view alongside the existing `event`, `ticketTypes`, `recentOrders`.
    - _Requirements: 1.4, 2.1, 3.1, 4.1, 4.2, 4.4, 5.1_

  - [~] 9.4 Feature test for publish gating (`PublishGatingTest`)
    - Publish succeeds with a ticket type + `starts_at`; refused with blocker list when either is missing (stays unpublished); unpublish always works regardless of blockers; non-admin roles get 403; foreign event gets 404.
    - _Requirements: 1.1, 1.2, 1.3, 1.5, 1.6_

  - [~] 9.5 Property test: unpublish is unconditional
    - **Property 2: Unpublish is unconditional**
    - Over generated Events in any state (including unmet blockers), assert unpublishing sets `is_published` to false.
    - **Validates: Requirements 1.3**

  - [~] 9.6 Feature test for the QR endpoint (`EventQrTest`)
    - Admin gets 200 with `Content-Type: image/png` and non-empty bytes; non-admin roles get 403; foreign event gets 404; when `QrService` is bound to a fake that throws, the endpoint returns an error, not a PNG.
    - _Requirements: 4.3, 4.5, 4.6, 4.7_

- [ ] 10. Create the EventReportController
  - Create `app/Http/Controllers/EventReportController.php` with a constructor-injected `EventReportService`. `show(Event $event): View` authorizes `ACTION_VIEW_REPORTS`, then returns `dashboard.events.report` with `event` and `report` (`EventReportService::for($event)`). Tenant scoping yields 404 for foreign events; the gate yields 403.
  - _Requirements: 6.1, 6.2, 6.7, 6.8_

  - [~] 10.1 Feature test for the report page (`EventReportPageTest`)
    - Accountant (and owner) get 200 and see the event's figures; admin/scanner get 403; foreign event gets 404; no CSV export link/route is present; figures match the shared service.
    - _Requirements: 6.1, 6.2, 6.7, 6.8, 6.9_

- [~] 11. Add the new routes
  - In `routes/web.php`, inside the existing dashboard group, add `GET /events/{event}/qr` => `EventController@qr` named `events.qr`, and `GET /events/{event}/report` => `EventReportController@show` named `events.report`. No CSV export route.
  - _Requirements: 4.3, 6.1, 6.9_

- [~] 12. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [ ] 13. Add the additive Blade partials and report page, and wire the show page
  - [~] 13.1 Create `resources/views/dashboard/events/_readiness.blade.php`
    - Render each `ChecklistItem` with a satisfied/unsatisfied indicator and a "required to publish" badge on blocking items.
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

  - [~] 13.2 Create `resources/views/dashboard/events/_capacity.blade.php`
    - Render the static explainer copy (optional overall ceiling, null = unlimited, interaction with per-type sum) plus a non-blocking warning keyed on `CapacityComparison::state()` for `EVENT_BINDS` and `TYPES_BIND`.
    - _Requirements: 3.1, 3.2, 3.3_

  - [~] 13.3 Create `resources/views/dashboard/events/_share.blade.php`
    - Show the public URL, a copy-link button (inline Clipboard API script via `@push('scripts')`), a link to the `events.qr` endpoint, and the note that the URL/QR go live only after publishing.
    - _Requirements: 4.1, 4.2, 4.4_

  - [~] 13.4 Create `resources/views/dashboard/events/_summary.blade.php`
    - Render Tickets_Sold, Gross_Revenue, Net_To_Company, Capacity_Utilisation, and confirmed order count from the `EventReport`.
    - _Requirements: 5.1_

  - [~] 13.5 Create `resources/views/dashboard/events/report.blade.php`
    - Render core metrics (6.2), the per-ticket-type breakdown table (6.3), the orders-by-status breakdown (6.4), and the sales-over-time-by-day trend (6.5). No CSV export control.
    - _Requirements: 6.2, 6.3, 6.4, 6.5, 6.9_

  - [~] 13.6 Modify `resources/views/dashboard/events/show.blade.php`
    - Include the four partials additively (leaving existing content/controls intact), disable/annotate the publish button when `!$event->isPublishable()`, render `session('publish_errors')` if present, and add a link to the report page.
    - _Requirements: 1.4, 2.5, 5.1_

- [~] 14. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise. Confirm `CapacityReservationServiceTest` and `AccountantReportingTest` remain green (engine and company report untouched).

## Notes

- Tasks marked with `*` are optional (test tasks) and can be skipped for a faster MVP.
- Each task references specific requirements/properties for traceability.
- Property tests use the `giorgiosironi/eris` library, extend `tests/PBT/PbtTestCase.php`, run a minimum of 100 iterations, and are tagged `Feature: event-management-and-reporting, Property {n}: {text}`.
- Feature/unit tests use `RefreshDatabase` and existing role/model factories, matching `EventManagementTest` and `AccountantReportingTest`.
- Verify with the repo's existing commands: `composer test` or `php artisan test` (PHPUnit). No CSV export is added anywhere; `CapacityReservationService` is not modified.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1", "2.1", "2.2", "2.3", "5"] },
    { "id": 1, "tasks": ["1.1", "2.4", "3", "6"] },
    { "id": 2, "tasks": ["3.1", "3.2", "3.3", "3.4", "6.1", "6.2", "6.3", "6.4", "6.5", "6.6", "7"] },
    { "id": 3, "tasks": ["7.1", "9.1", "9.2", "9.3", "10"] },
    { "id": 4, "tasks": ["9.4", "9.5", "9.6", "10.1", "11"] },
    { "id": 5, "tasks": ["13.1", "13.2", "13.3", "13.4", "13.5"] },
    { "id": 6, "tasks": ["13.6"] }
  ]
}
```
