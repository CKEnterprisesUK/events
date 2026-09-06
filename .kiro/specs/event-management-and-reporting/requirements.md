# Requirements Document

## Introduction

This feature enhances the existing multi-tenant Laravel event-ticketing platform's dashboard around a single event's lifecycle. It adds publish gating so an event cannot go live until it is ready, a readiness checklist and capacity explainer on the manage-event page, a QR-code and public-link sharing panel, and per-event reporting (an inline summary plus a dedicated report page).

All additions follow existing platform conventions: dashboard event-management actions are gated by the `ACTION_MANAGE_EVENTS` authorization action; reporting is gated by the `ACTION_VIEW_REPORTS` authorization action; all data is tenant-scoped so events belonging to another company resolve as 404; monetary values are integer minor-currency units; views are Blade; and reporting accounting logic reuses the definitions already established in `ReportController`. This enhancement does not change the reservation engine (`CapacityReservationService`).

## Glossary

- **Platform**: The existing multi-tenant Laravel event-ticketing application being enhanced.
- **Manage_Event_Page**: The dashboard view at `resources/views/dashboard/events/show.blade.php` where an event is managed.
- **Event_Report_Page**: The new dedicated per-event report view served at the `events/{event}/report` dashboard route.
- **Event_Manager**: An authenticated dashboard user holding the `ACTION_MANAGE_EVENTS` authorization action (Admin role, and Owner/Super_Admin via bypass).
- **Report_Viewer**: An authenticated dashboard user holding the `ACTION_VIEW_REPORTS` authorization action (Accountant role, and Owner/Super_Admin via bypass).
- **Event**: A company-owned scheduled occurrence with an optional `starts_at` timestamp, an optional overall `capacity` (NULL = unlimited), and an `is_published` flag.
- **Ticket_Type**: A purchasable tier belonging to an event, each with its own capacity.
- **Publish_Blocker**: A missing prerequisite that prevents an event from being published: at least one Ticket_Type and a set `starts_at`.
- **Confirmed_Order**: An order whose status is `paid` or `free_confirmed`, as defined by the existing reporting logic.
- **Tickets_Sold**: The count of `valid` tickets belonging to Confirmed_Orders for an event.
- **Gross_Revenue**: The sum of order totals of an event's Confirmed_Orders, in integer minor-currency units.
- **Net_To_Company**: For a Confirmed_Order, its order total minus its application fee; summed across an event's Confirmed_Orders, in integer minor-currency units.
- **Capacity_Utilisation**: Tickets_Sold expressed relative to the applicable event capacity ceiling.
- **Qr_Service**: The existing `QrService`, whose `png(string $payload, int $size)` method renders a PNG QR code and requires the GD extension.
- **Public_Event_URL**: The customer-facing event page URL produced by `route('event.page', [companySlug, eventId])`.

## Requirements

### Requirement 1: Publish gating

**User Story:** As an Event_Manager, I want the Platform to prevent publishing an event before it is ready, so that customers never see an event that has no tickets or no date.

#### Acceptance Criteria

1. WHEN an Event_Manager requests to publish an Event that has at least one Ticket_Type AND a non-null `starts_at`, THE Platform SHALL publish the Event.
2. IF an Event_Manager requests to publish an Event that has no Ticket_Type OR a null `starts_at`, THEN THE Platform SHALL leave the Event unpublished and SHALL display a message listing each unmet Publish_Blocker.
3. WHEN an Event_Manager requests to unpublish an Event, THE Platform SHALL unpublish the Event regardless of Publish_Blocker state.
4. WHILE an Event has one or more unmet Publish_Blockers, THE Manage_Event_Page SHALL present the publish control in a state that communicates the Event is not yet publishable.
5. WHEN an Event_Manager who lacks `ACTION_MANAGE_EVENTS` requests to publish or unpublish an Event, THE Platform SHALL deny the request with an HTTP 403 response and leave the Event's published state unchanged.
6. WHEN any user requests to publish or unpublish an Event that belongs to another company, THE Platform SHALL respond with HTTP 404.

### Requirement 2: Setup / readiness checklist

**User Story:** As an Event_Manager, I want a readiness checklist on the manage-event page, so that I can see at a glance what an event still needs before it can go live.

#### Acceptance Criteria

1. WHERE an Event_Manager views the Manage_Event_Page, THE Manage_Event_Page SHALL display a readiness panel listing the following items: event name, start date, venue, presence of at least one Ticket_Type, and capacity sanity.
2. THE Manage_Event_Page SHALL indicate for each readiness item whether the item is currently satisfied for the Event.
3. THE Manage_Event_Page SHALL identify the start-date item and the at-least-one-Ticket_Type item as Publish_Blockers.
4. THE Manage_Event_Page SHALL identify the venue item and the capacity-sanity item as advisory items that do not block publishing.
5. THE readiness panel SHALL be additive, leaving the existing Manage_Event_Page content and controls in place.

### Requirement 3: Capacity explainer and soft warnings

**User Story:** As an Event_Manager, I want to understand how the overall event capacity relates to the per-ticket-type capacities, so that I can set capacity numbers with confidence.

#### Acceptance Criteria

1. WHERE an Event_Manager views the Manage_Event_Page, THE Manage_Event_Page SHALL display an explanation that the Event `capacity` is an optional overall ceiling, that a NULL `capacity` means unlimited, and that it interacts with the sum of the per-Ticket_Type capacities.
2. IF an Event has a non-null `capacity` that is less than the sum of its Ticket_Type capacities, THEN THE Manage_Event_Page SHALL display a non-blocking warning stating that the Event `capacity` will bind first.
3. IF an Event has a non-null `capacity` that is greater than the sum of its Ticket_Type capacities, THEN THE Manage_Event_Page SHALL display a non-blocking warning stating that the sum of Ticket_Type capacities will bind first.
4. WHEN THE Platform displays a capacity warning, THE Platform SHALL allow the Event_Manager to save, publish, and unpublish the Event without resolving the warning.
5. THE Platform SHALL leave the behaviour of `CapacityReservationService` unchanged.

### Requirement 4: QR code and public link panel

**User Story:** As an Event_Manager, I want a shareable public link and downloadable QR code for an event at any stage, so that I can prepare marketing materials before and after the event is published.

#### Acceptance Criteria

1. WHERE an Event_Manager views the Manage_Event_Page for either a draft or a published Event, THE Manage_Event_Page SHALL display the Public_Event_URL for that Event.
2. THE Manage_Event_Page SHALL provide a copy-link affordance for the Public_Event_URL.
3. WHEN an Event_Manager requests the Event's QR code, THE Platform SHALL return a downloadable PNG QR code encoding the Public_Event_URL, generated via `QrService::png`.
4. THE Manage_Event_Page SHALL display a note stating that the Public_Event_URL and its QR code become live to customers only after the Event is published.
5. WHEN an Event_Manager who lacks `ACTION_MANAGE_EVENTS` requests the Event's QR code, THE Platform SHALL deny the request with an HTTP 403 response.
6. WHEN any user requests the QR code for an Event that belongs to another company, THE Platform SHALL respond with HTTP 404.
7. IF the GD extension is unavailable when a QR code is requested, THEN THE Platform SHALL return an error rather than a PNG.

### Requirement 5: Inline per-event stats summary

**User Story:** As an Event_Manager, I want a compact stats summary on the manage-event page, so that I can see how an event is performing without leaving the page.

#### Acceptance Criteria

1. WHERE an Event_Manager views the Manage_Event_Page, THE Manage_Event_Page SHALL display a compact summary panel containing Tickets_Sold, Gross_Revenue, Net_To_Company, Capacity_Utilisation, and the count of Confirmed_Orders for the Event.
2. THE Manage_Event_Page SHALL compute Tickets_Sold as the count of `valid` tickets belonging to Confirmed_Orders of the Event.
3. THE Manage_Event_Page SHALL compute Net_To_Company as the sum over the Event's Confirmed_Orders of each order's total minus its application fee, in integer minor-currency units.
4. WHERE an Event has a null `capacity`, THE Manage_Event_Page SHALL present Capacity_Utilisation as unlimited rather than as a percentage of a fixed ceiling.

### Requirement 6: Dedicated per-event report page

**User Story:** As a Report_Viewer, I want a dedicated per-event report page, so that I can review detailed sales, revenue, and order breakdowns for a single event.

#### Acceptance Criteria

1. WHEN a Report_Viewer requests the Event_Report_Page for an Event via the `events/{event}/report` route, THE Platform SHALL display the Event_Report_Page for that Event.
2. THE Event_Report_Page SHALL display the core metrics Tickets_Sold, Gross_Revenue, Net_To_Company, Capacity_Utilisation, and Confirmed_Order count for the Event.
3. THE Event_Report_Page SHALL display a per-Ticket_Type breakdown table listing, for each Ticket_Type, the tickets sold, remaining capacity, and revenue.
4. THE Event_Report_Page SHALL display an orders-by-status breakdown covering the paid, reserved, refunded, cancelled, and comp order states.
5. THE Event_Report_Page SHALL display a sales-over-time trend of tickets sold and revenue aggregated by day.
6. THE Event_Report_Page SHALL derive its accounting figures using the same definitions as the existing `ReportController`: Confirmed_Orders are `paid` plus `free_confirmed`; Net_To_Company is order total minus application fee; Tickets_Sold counts `valid` tickets on Confirmed_Orders.
7. WHEN a user who lacks `ACTION_VIEW_REPORTS` requests the Event_Report_Page, THE Platform SHALL deny the request with an HTTP 403 response.
8. WHEN any user requests the Event_Report_Page for an Event that belongs to another company, THE Platform SHALL respond with HTTP 404.
9. THE Event_Report_Page SHALL NOT offer CSV export.
