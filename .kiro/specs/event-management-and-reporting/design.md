# Design Document

## Overview

This feature enhances the existing multi-tenant Laravel event-ticketing platform's per-event dashboard. It is deliberately **additive**: it introduces publish gating, a readiness checklist, a capacity explainer, a QR/public-link panel, an inline stats summary, and a dedicated per-event report page — without altering the reservation engine (`CapacityReservationService`) or any existing checkout, webhook, or fulfilment path.

The design follows the platform's established conventions:

- **Authorization** goes through the existing gates registered from `RoleAuthorization::actions()` in `AppServiceProvider`. Event management is gated on `ACTION_MANAGE_EVENTS`; reporting is gated on `ACTION_VIEW_REPORTS`. The `Gate::before` Super_Admin bypass and the Owner's union-of-all-actions behaviour are inherited unchanged.
- **Tenant scoping** is inherited from the `dashboard.tenant` middleware group and the global `company_id` scope on the `Event`, `TicketType`, `Order`, and `Ticket` models (`BelongsToCompany`). Any route that route-model-binds an `Event` under `/dashboard` therefore resolves cross-company rows as 404 with no extra code.
- **Money** stays in integer minor-currency units end to end.
- **Views** are Blade, extending `layouts.dashboard`.
- **Accounting definitions** (confirmed = `paid` + `free_confirmed`; net = `order_total_minor − application_fee_minor`; tickets sold = `valid` tickets on confirmed orders) are reused from `ReportController` by extracting them into a single shared service so both surfaces derive from one source of truth.
- **Helpers / value objects**: the readiness checklist and capacity explainer follow the existing `Services/Onboarding/OnboardingChecklist` → `OnboardingProgress` / `OnboardingStep` precedent (a stateless service returning immutable value objects the view renders).

Code examples are in PHP (Laravel), matching the existing codebase.

## Architecture

### Request surfaces

```
GET  /dashboard/events/{event}            EventController@show      (ACTION_MANAGE_EVENTS)  [modified]
POST /dashboard/events/{event}/publish    EventController@publish   (ACTION_MANAGE_EVENTS)  [modified]
GET  /dashboard/events/{event}/qr         EventController@qr        (ACTION_MANAGE_EVENTS)  [new]
GET  /dashboard/events/{event}/report     EventReportController@show(ACTION_VIEW_REPORTS)   [new]
```

All four live inside the existing `['auth','company.active','session.timeout','dashboard.tenant']` group with the `dashboard.` name prefix, so they inherit auth, active-company enforcement, idle timeout, and tenant binding.

### Component map

```
routes/web.php
  └─ dashboard group
       ├─ events.show      → EventController@show      (adds readiness/capacity/qr/summary view data)
       ├─ events.publish   → EventController@publish   (adds blocker validation)
       ├─ events.qr        → EventController@qr         [new action]
       └─ events.report    → EventReportController@show [new controller]

app/Models/Event.php
  ├─ publishBlockers(): array        [new] — single source of truth for publish prerequisites
  └─ isPublishable(): bool           [new] — sugar over publishBlockers() === []

app/Services/EventReadiness.php               [new] stateless helper (value-object producer)
  ├─ checklist(Event): EventReadinessReport
  └─ capacity(Event): CapacityComparison
app/Services/Events/EventReadinessReport.php   [new] value object (list<ChecklistItem>)
app/Services/Events/ChecklistItem.php          [new] value object (key,label,satisfied,blocking)
app/Services/Events/CapacityComparison.php     [new] value object (state,event_capacity,types_sum)

app/Services/EventReportService.php            [new] the shared accounting source of truth
  └─ for(Event): EventReport                    (value object with all figures)
app/Services/Reporting/EventReport.php         [new] value object

app/Http/Controllers/ReportController.php       [refactored] delegates per-event accounting to EventReportService

resources/views/dashboard/events/show.blade.php      [modified] additive panels
resources/views/dashboard/events/report.blade.php     [new]
resources/views/dashboard/events/_readiness.blade.php [new partial]
resources/views/dashboard/events/_capacity.blade.php  [new partial]
resources/views/dashboard/events/_share.blade.php     [new partial]
resources/views/dashboard/events/_summary.blade.php   [new partial]
```

## Components and Interfaces

### 1. Publish gating (Requirement 1)

The prerequisites are defined **once** on the `Event` model so the controller (enforcement) and the view (presentation) share them.

```php
// app/Models/Event.php

/**
 * The unmet publish prerequisites for this Event, as an ordered map of
 * blocker key => human message. Empty array === publishable. (Req 1.1, 1.2)
 *
 * @return array<string, string>
 */
public function publishBlockers(): array
{
    $blockers = [];

    if ($this->starts_at === null) {
        $blockers['starts_at'] = 'Set a start date and time.';
    }

    // ticketTypes()->exists() is tenant-scoped like the Event itself.
    if (! $this->ticketTypes()->exists()) {
        $blockers['ticket_types'] = 'Add at least one ticket type.';
    }

    return $blockers;
}

public function isPublishable(): bool
{
    return $this->publishBlockers() === [];
}
```

`EventController@publish` validates before mutating:

```php
public function publish(Event $event): RedirectResponse
{
    Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS);

    $blockers = $event->publishBlockers();

    if ($blockers !== []) {
        // Leave the Event unpublished; report every unmet blocker. (Req 1.2)
        return redirect()
            ->route('dashboard.events.show', $event)
            ->with('publish_errors', array_values($blockers));
    }

    $event->publish();
    $this->storefrontListing->forget($event->company);

    return redirect()
        ->route('dashboard.events.show', $event)
        ->with('status', 'Event published.');
}
```

`unpublish()` is left exactly as-is — it never consults blockers (Requirement 1.3). Authorization (403, Requirement 1.5) and tenant scoping (404, Requirement 1.6) are already provided by the gate and the `dashboard.tenant` binding; no new code is needed for them.

The view (Requirement 1.4) disables/annotates the publish button when `$event->isPublishable()` is false and renders `session('publish_errors')` if present.

### 2. Readiness checklist + capacity explainer (Requirements 2, 3)

A stateless `EventReadiness` service mirrors `OnboardingChecklist`, returning immutable value objects. This keeps the Blade view free of computation.

```php
// app/Services/Events/ChecklistItem.php
final class ChecklistItem
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $satisfied,
        public bool $blocking,   // true => an unmet item blocks publish
    ) {}
}
```

```php
// app/Services/EventReadiness.php
class EventReadiness
{
    /** Ordered readiness items for the Manage_Event_Page. (Req 2.1–2.4) */
    public function checklist(Event $event): EventReadinessReport
    {
        $blockers = $event->publishBlockers(); // single source of truth

        return new EventReadinessReport([
            new ChecklistItem('name',        'Event name',   $event->name !== '',              blocking: false),
            new ChecklistItem('starts_at',   'Start date',   ! isset($blockers['starts_at']),  blocking: true),
            new ChecklistItem('venue',       'Venue',        $this->filled($event->venue),     blocking: false),
            new ChecklistItem('ticket_types','Ticket type',  ! isset($blockers['ticket_types']), blocking: true),
            new ChecklistItem('capacity',    'Capacity sanity', $this->capacity($event)->isSane(), blocking: false),
        ]);
    }

    /** Compares the Event ceiling against the sum of ticket-type capacities. (Req 3.2, 3.3) */
    public function capacity(Event $event): CapacityComparison
    {
        $typesSum = (int) $event->ticketTypes()->sum('capacity');

        return new CapacityComparison(
            eventCapacity: $event->capacity,   // null => unlimited
            typesSum: $typesSum,
        );
    }

    private function filled(?string $v): bool { return $v !== null && trim($v) !== ''; }
}
```

```php
// app/Services/Events/CapacityComparison.php
final class CapacityComparison
{
    public const UNLIMITED   = 'unlimited';    // event capacity is null
    public const EVENT_BINDS  = 'event_binds';  // capacity < typesSum
    public const TYPES_BIND   = 'types_bind';   // capacity > typesSum
    public const BALANCED     = 'balanced';     // capacity === typesSum

    public function __construct(public ?int $eventCapacity, public int $typesSum) {}

    public function state(): string
    {
        if ($this->eventCapacity === null)            return self::UNLIMITED;
        if ($this->eventCapacity <  $this->typesSum)  return self::EVENT_BINDS;
        if ($this->eventCapacity >  $this->typesSum)  return self::TYPES_BIND;
        return self::BALANCED;
    }

    /** Advisory only — never blocks publish. (Req 3.4) */
    public function isSane(): bool
    {
        return $this->state() !== self::EVENT_BINDS && $this->state() !== self::TYPES_BIND
            ? true
            : true; // capacity is always advisory; sanity is informational, never a blocker
    }
}
```

The `_readiness.blade.php` partial renders each item with a satisfied/unsatisfied indicator and a "required to publish" badge on blocking items. `_capacity.blade.php` renders the static explainer copy (Requirement 3.1) plus a non-blocking warning keyed on `state()`:

- `EVENT_BINDS` → "The event capacity ({{n}}) is below the sum of ticket-type capacities ({{sum}}); the event capacity will bind first." (Requirement 3.2)
- `TYPES_BIND` → "The sum of ticket-type capacities ({{sum}}) is below the event capacity ({{n}}); the ticket-type capacities will bind first." (Requirement 3.3)

`CapacityReservationService` is not referenced or touched (Requirement 3.5).

### 3. QR + public link panel (Requirement 4)

The public URL is built with the existing named route:

```php
$publicUrl = route('event.page', ['companySlug' => $event->company->slug, 'event' => $event->id]);
```

`EventController@show` passes `$publicUrl` to the view. The `_share.blade.php` partial shows the URL, a copy-link button (a small inline script using the Clipboard API — same lightweight `@push('scripts')` pattern already in `show.blade.php`), a link to the new QR endpoint, and the note that the URL/QR go live only after publishing (Requirements 4.1, 4.2, 4.4).

New streaming action reusing `QrService::png`:

```php
public function qr(Event $event, QrService $qr): Response
{
    Gate::authorize(RoleAuthorization::ACTION_MANAGE_EVENTS); // 403 (Req 4.5)

    $url = route('event.page', ['companySlug' => $event->company->slug, 'event' => $event->id]);

    try {
        $png = $qr->png($url, 512);
    } catch (\Throwable $e) {
        // GD missing (or any render failure): return an error, never a PNG. (Req 4.7)
        abort(500, 'QR code generation is unavailable on this server.');
    }

    return response($png, 200, [
        'Content-Type' => 'image/png',
        'Content-Disposition' => 'attachment; filename="event-'.$event->id.'-qr.png"',
    ]);
}
```

Tenant scoping (Requirement 4.6) is inherited: the bound `Event` for another company never resolves under the `dashboard.tenant` scope, yielding 404 before the action runs. `QrService::png` already throws when the payload can't be rendered; catching `Throwable` covers the GD-unavailable case cleanly. The QR encodes the public URL for **both** draft and published events (Requirement 4.3) — publishing only governs whether the destination page is live, not whether the code can be produced.

### 4. Inline per-event stats summary + dedicated report page (Requirements 5, 6)

#### Shared accounting service (single source of truth)

The accounting definitions currently living in `ReportController` (the `CONFIRMED_STATUSES` constant, net-to-company, tickets-sold, and the per-event breakdown) are extracted into `EventReportService`. `ReportController::perEventBreakdown` is refactored to call this service so the company-wide report and the per-event report can never diverge (Requirement 6.6).

```php
// app/Services/EventReportService.php
class EventReportService
{
    /** Same definition ReportController used. (Req 6.6) */
    public const CONFIRMED_STATUSES = [Order::STATUS_PAID, Order::STATUS_FREE_CONFIRMED];

    /** Full per-event report used by the summary panel and the report page. */
    public function for(Event $event): EventReport
    {
        $confirmed = $event->orders()
            ->whereIn('status', self::CONFIRMED_STATUSES)
            ->get();

        $confirmedIds = $confirmed->pluck('id')->all();

        $ticketsSold = $confirmedIds === [] ? 0 : Ticket::query()
            ->whereIn('order_id', $confirmedIds)
            ->where('status', Ticket::STATUS_VALID)
            ->count();

        $orderTotal      = (int) $confirmed->sum('order_total_minor');
        $applicationFees = (int) $confirmed->sum('application_fee_minor');

        return new EventReport(
            confirmedOrders: $confirmed->count(),
            ticketsSold:     $ticketsSold,
            grossRevenueMinor: $orderTotal,                        // Gross_Revenue (Req 5.1, 6.2)
            netToCompanyMinor: $orderTotal - $applicationFees,     // Net (Req 5.3, 6.6)
            capacity:        $event->capacity,                     // null => unlimited (Req 5.4)
            perTicketType:   $this->perTicketType($event, $confirmedIds),   // Req 6.3
            ordersByStatus:  $this->ordersByStatus($event),                 // Req 6.4
            salesByDay:      $this->salesByDay($confirmed, $confirmedIds),   // Req 6.5
        );
    }

    // perTicketType(): for each type — sold = valid confirmed tickets of that
    //   type; remaining = capacity - sold_count - reserved_count; revenue
    //   derived from confirmed tickets of that type. (Req 6.3)
    // ordersByStatus(): count of the Event's orders in each of paid, reserved,
    //   refunded, cancelled, and comp (free_confirmed) states. (Req 6.4)
    // salesByDay(): confirmed orders grouped by fulfilment day → tickets + revenue. (Req 6.5)
}
```

```php
// app/Services/Reporting/EventReport.php — immutable value object
final class EventReport
{
    public function __construct(
        public int $confirmedOrders,
        public int $ticketsSold,
        public int $grossRevenueMinor,
        public int $netToCompanyMinor,
        public ?int $capacity,
        public array $perTicketType,   // list<{ type_id, name, sold, remaining, revenue_minor }>
        public array $ordersByStatus,  // map<status,int>
        public array $salesByDay,      // list<{ day, tickets, revenue_minor }>
    ) {}

    /** Capacity_Utilisation: null capacity => "unlimited". (Req 5.4) */
    public function utilisation(): float|string
    {
        if ($this->capacity === null || $this->capacity === 0) {
            return 'unlimited';
        }
        return round($this->ticketsSold / $this->capacity * 100, 1);
    }
}
```

#### Inline summary (Requirement 5)

`EventController@show` resolves `EventReportService::for($event)` and passes the `EventReport` to the `_summary.blade.php` partial, which shows Tickets_Sold, Gross_Revenue, Net_To_Company, Capacity_Utilisation, and confirmed order count (Requirement 5.1). This panel renders for the Event_Manager (already `ACTION_MANAGE_EVENTS`-gated at the route).

#### Report page (Requirement 6)

A dedicated controller, gated on `ACTION_VIEW_REPORTS`:

```php
// app/Http/Controllers/EventReportController.php
class EventReportController extends Controller
{
    public function __construct(private EventReportService $reports) {}

    public function show(Event $event): View
    {
        Gate::authorize(RoleAuthorization::ACTION_VIEW_REPORTS); // 403 (Req 6.7)

        return view('dashboard.events.report', [
            'event'  => $event,        // tenant-scoped → 404 for foreign events (Req 6.8)
            'report' => $this->reports->for($event),
        ]);
    }
}
```

`report.blade.php` renders the core metrics (Requirement 6.2), the per-ticket-type table (Requirement 6.3), the orders-by-status breakdown (Requirement 6.4), and the sales-over-time-by-day trend (Requirement 6.5). It offers **no** CSV export control or route (Requirement 6.9). The Manage_Event_Page links to this report page.

### Route additions (`routes/web.php`, inside the existing dashboard group)

```php
Route::get('/events/{event}/qr', [EventController::class, 'qr'])->name('events.qr');
Route::get('/events/{event}/report', [EventReportController::class, 'show'])->name('events.report');
```

## Data Models

No schema changes. The feature reads existing columns only:

- `events`: `name`, `venue`, `starts_at`, `capacity`, `is_published`, `company_id`.
- `ticket_types`: `capacity`, `sold_count`, `reserved_count`, `price_minor`, `event_id`.
- `orders`: `status`, `order_total_minor`, `application_fee_minor`, `fulfilled_at`, `event_id`.
- `tickets`: `status`, `order_id`, `ticket_type_id`.

All value objects (`ChecklistItem`, `CapacityComparison`, `EventReadinessReport`, `EventReport`) are transient, request-lifetime objects with no persistence.

## Error Handling

- **Publish blocked** (Requirement 1.2): redirect back with `publish_errors` in the session; no exception, no mutation.
- **Unauthorized** (Requirements 1.5, 4.5, 6.7): `Gate::authorize` throws `AuthorizationException` → HTTP 403 via the framework handler. No state change.
- **Cross-company / not found** (Requirements 1.6, 4.6, 6.8): route-model binding under the tenant scope yields no row → HTTP 404. No state change.
- **QR generation failure / GD missing** (Requirement 4.7): `QrService::png` throws; the `qr` action catches `Throwable` and aborts 500 with a message, returning an error page rather than PNG bytes.

## Testing Approach

Feature tests use `RefreshDatabase` and the existing role factories (`User::factory()->admin()`, `->accountant()`, `->scanner()`) and model factories, matching `EventManagementTest` and `AccountantReportingTest`.

- **Publish gating** (`PublishGatingTest`): publish succeeds when a ticket type and `starts_at` exist; publish is refused and lists blockers when either is missing (state stays unpublished); unpublish always works regardless of blockers; non-admin roles get 403; foreign event gets 404.
- **QR endpoint** (`EventQrTest`): admin gets 200 with `Content-Type: image/png` and non-empty bytes; non-admin roles get 403; foreign event gets 404; when `QrService` is bound to a fake that throws, the endpoint returns an error, not a PNG.
- **Report page** (`EventReportPageTest`): accountant (and owner) get 200 and see the event's figures; admin/scanner get 403; foreign event gets 404; no CSV export link/route is present; figures match the shared service.
- **Readiness/capacity helper unit tests** (`EventReadinessTest`): checklist item set and satisfied/blocking flags for varied event states; capacity comparison states across `<`, `>`, `=`, and null.
- **Accounting service unit tests** (`EventReportServiceTest`): tickets-sold counts only valid tickets on confirmed orders; net = order_total − application_fee over confirmed orders; utilisation is "unlimited" for null capacity; per-type/by-status/by-day aggregations; parity with `ReportController`'s per-event breakdown.
- **Untouched engine**: no changes to `CapacityReservationService`; its existing test suite must keep passing unchanged (Requirement 3.5).

Property tests use a PBT library for PHP (e.g. a generator-based approach over Eloquent factories), a minimum of 100 iterations each, tagged `Feature: event-management-and-reporting, Property {n}: {text}`.

## Correctness Properties

*A property is a characteristic or behavior that should hold true across all valid executions of a system — a formal statement about what the system should do. Properties bridge human-readable specifications and machine-verifiable correctness guarantees.*

### Property 1: Publish gate is exactly the blocker predicate

*For any* Event, publishing succeeds and sets `is_published` true if and only if `publishBlockers()` is empty; when it is non-empty the Event stays unpublished and the reported errors are exactly the unmet prerequisites (missing `starts_at` and/or no ticket type).

**Validates: Requirements 1.1, 1.2**

### Property 2: Unpublish is unconditional

*For any* Event in any state (including one with unmet blockers), unpublishing sets `is_published` to false.

**Validates: Requirements 1.3**

### Property 3: Checklist reflects event state

*For any* Event, the readiness checklist contains exactly the items {name, start date, venue, ticket type, capacity sanity}, and each item's `satisfied` flag equals the corresponding predicate evaluated on the Event's current state.

**Validates: Requirements 2.1, 2.2**

### Property 4: Blocking checklist items match the publish source of truth

*For any* Event, the set of checklist items marked `blocking` is exactly {start date, ticket type}, and an unmet blocking item corresponds to a key present in `publishBlockers()`; the venue and capacity-sanity items are never blocking.

**Validates: Requirements 2.3, 2.4**

### Property 5: Capacity comparison classifies the ceiling correctly

*For any* Event, the capacity comparison state is `unlimited` when `capacity` is null, `event_binds` when `capacity` is less than the sum of ticket-type capacities, `types_bind` when it is greater, and `balanced` when equal.

**Validates: Requirements 3.2, 3.3**

### Property 6: Capacity warnings never block publishing

*For any* Event whose two real prerequisites are satisfied, `publishBlockers()` is empty regardless of the capacity comparison state, so any capacity mismatch never prevents save, publish, or unpublish.

**Validates: Requirements 3.4**

### Property 7: Confirmed-order accounting definitions

*For any* population of an Event's orders and tickets, Tickets_Sold equals the count of `valid` tickets belonging to orders whose status is `paid` or `free_confirmed`, and Net_To_Company equals the sum over those confirmed orders of `order_total_minor − application_fee_minor`.

**Validates: Requirements 5.2, 5.3, 6.2, 6.6**

### Property 8: Capacity utilisation honours unlimited

*For any* Event, Capacity_Utilisation is presented as unlimited when `capacity` is null, and otherwise as Tickets_Sold relative to the fixed `capacity` ceiling.

**Validates: Requirements 5.4**

### Property 9: Per-ticket-type breakdown is consistent

*For any* Event, each per-ticket-type row reports sold as the count of `valid` tickets of that type on confirmed orders, remaining as `capacity − sold_count − reserved_count`, and revenue derived consistently from that type's confirmed tickets.

**Validates: Requirements 6.3**

### Property 10: Orders-by-status breakdown is exhaustive

*For any* Event, each order-status bucket (paid, reserved, refunded, cancelled, comp) reports a count equal to the number of the Event's orders in that status.

**Validates: Requirements 6.4**

### Property 11: Sales-over-time matches grouped sums

*For any* set of an Event's confirmed orders, each day in the sales-over-time trend reports tickets and revenue equal to the sums over the confirmed orders fulfilled on that day.

**Validates: Requirements 6.5**

### Property 12: Per-event figures agree with the company report

*For any* Event, the figures produced by `EventReportService` equal the per-event figures produced by `ReportController`'s per-event breakdown for that same Event, since both derive from the single shared accounting source of truth.

**Validates: Requirements 6.6**
