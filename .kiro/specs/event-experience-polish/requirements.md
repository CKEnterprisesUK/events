# Requirements Document

## Introduction

This feature is a follow-on enhancement to the `event-management-and-reporting` spec for the existing multi-tenant Laravel event-ticketing platform. It polishes the single-event experience without changing the platform's authorization, tenancy, or accounting foundations. It reorganises the manage-event page into tabs, introduces a per-ticket-type capacity mode (capped vs shared pool) layered on top of the overall event capacity, adds an event location mode (in-person with a geocoded map, or online), wires up the previously unused hero-image columns, and moves ticket-type and complimentary-ticket editing into an inline/modal experience within the tabbed layout.

All additions follow existing platform conventions: dashboard event-management actions are gated by the `ACTION_MANAGE_EVENTS` authorization action; reporting is gated by the `ACTION_VIEW_REPORTS` authorization action; all data is tenant-scoped via the `dashboard.tenant` middleware and the global `company_id` scope, so events belonging to another company resolve as HTTP 404; monetary values are integer minor-currency units; views are Blade. `CapacityReservationService` remains the sole enforcement point for capacity and its no-oversell guarantees are preserved. `Event::publishBlockers()` / `Event::isPublishable()` and the readiness surface remain the single source of truth for publish gating, extended here with one new blocker. Existing routes, controllers, and request validation are preserved except where a requirement explicitly extends them.

## Glossary

- **Platform**: The existing multi-tenant Laravel event-ticketing application being enhanced.
- **Manage_Event_Page**: The dashboard view at `resources/views/dashboard/events/show.blade.php` (the `dashboard/events/{event}` show route) where an event is managed.
- **Public_Event_Page**: The customer-facing event page produced by `route('event.page', [companySlug, eventId])`.
- **Event_Manager**: An authenticated dashboard user holding the `ACTION_MANAGE_EVENTS` authorization action (Admin role, and Owner/Super_Admin via bypass).
- **Report_Viewer**: An authenticated dashboard user holding the `ACTION_VIEW_REPORTS` authorization action (Accountant role, and Owner/Super_Admin via bypass).
- **Event**: A company-owned scheduled occurrence with an optional `starts_at`, an optional overall `capacity` (NULL = unlimited), an `is_published` flag, per-event branding overrides, and (added here) a `poster_path` hero image, a Location_Mode, and location fields.
- **Ticket_Type**: A purchasable tier belonging to an Event, with `price_minor`, `capacity`, `sold_count`, `reserved_count`, a sale window, and (added here) a Capacity_Mode.
- **Reservation_Engine**: The existing `CapacityReservationService`, the sole component that reserves, commits, releases, and returns Ticket_Type and Event capacity under `SELECT ... FOR UPDATE`, guaranteeing no oversell.
- **Overall_Capacity**: The Event's `capacity` value; when non-null it is the hard ceiling on confirmed + reserved units summed across all of the Event's Ticket_Types. NULL = unlimited.
- **Capacity_Mode**: A per-Ticket_Type setting with value `capped` or `shared_pool` that governs whether the Ticket_Type enforces its own per-type limit.
- **Capped_Type**: A Ticket_Type whose Capacity_Mode is `capped`; it enforces its own hard `capacity` limit in addition to the Overall_Capacity.
- **Shared_Pool_Type**: A Ticket_Type whose Capacity_Mode is `shared_pool`; it has no separate per-type limit and draws only from the Overall_Capacity.
- **Publish_Blocker**: A missing prerequisite that prevents publishing, surfaced by `Event::publishBlockers()`. This spec adds the shared-pool-without-overall-capacity blocker to the existing set.
- **Location_Mode**: A per-Event setting with value `in_person` or `online`.
- **In_Person_Event**: An Event whose Location_Mode is `in_person`; it has an address and stored latitude/longitude coordinates.
- **Online_Event**: An Event whose Location_Mode is `online`; it has no map and communicates joining information by email.
- **Coordinates**: A stored latitude/longitude pair (decimal degrees) locating an In_Person_Event.
- **Geocode**: The act of converting an address string to Coordinates via the OpenStreetMap Nominatim service.
- **Nominatim**: The OpenStreetMap geocoding service used to resolve an address to Coordinates.
- **Map_Widget**: A Leaflet/OpenStreetMap map rendered in the browser; on the Manage_Event_Page it lets the Event_Manager adjust the pin, and on the Public_Event_Page it displays the location.
- **Hero_Image**: The optional banner image stored in `events.poster_path` (per-Event) or `companies.poster_path` (company-level), uploaded via the existing branding upload approach.
- **Branding_Upload**: The existing branding/logo upload-and-storage mechanism (same storage disk and handling) reused for Hero_Image uploads.

## Requirements

### Requirement 1: Tabbed manage-event page

**User Story:** As an Event_Manager, I want the manage-event page organised into tabs, so that I can navigate a single event's sections without scrolling through one long page.

#### Acceptance Criteria

1. WHERE an Event_Manager views the Manage_Event_Page, THE Manage_Event_Page SHALL present its content under the tabs Overview, Ticket types, Location, Share & QR, Report, and Orders.
2. WHEN an Event_Manager selects a tab AND JavaScript is available, THE Manage_Event_Page SHALL show that tab's content and hide the other tabs' content without a full page reload.
3. WHERE JavaScript is unavailable, THE Manage_Event_Page SHALL keep the content of every tab reachable and visible on the page.
4. THE Manage_Event_Page SHALL retain the existing readiness checklist, inline stats summary, capacity explainer, share/QR panel, ticket-type management, complimentary-ticket issuance, and recent-orders list, each placed within the corresponding tab.
5. THE Manage_Event_Page SHALL continue to serve from the existing show route and controller without introducing new event-management routes for tab display.
6. WHEN a user who lacks `ACTION_MANAGE_EVENTS` requests the Manage_Event_Page, THE Platform SHALL deny the request with an HTTP 403 response.
7. WHEN any user requests the Manage_Event_Page for an Event that belongs to another company, THE Platform SHALL respond with HTTP 404.
8. THE Manage_Event_Page tab controls SHALL be keyboard-navigable and SHALL carry accessible labels identifying each tab.

### Requirement 2: Per-ticket-type capacity mode

**User Story:** As an Event_Manager, I want each ticket type to either enforce its own cap or draw from a shared event pool, so that I can model both fixed-allocation tiers and flexible general-admission pools without overselling.

#### Acceptance Criteria

1. THE Platform SHALL give each Ticket_Type a Capacity_Mode whose value is either `capped` or `shared_pool`.
2. WHERE a Ticket_Type is a Capped_Type, THE Platform SHALL require a `capacity` value in the range 1 to 1,000,000 inclusive.
3. WHERE a Ticket_Type is a Shared_Pool_Type, THE Platform SHALL NOT require a `capacity` value for that Ticket_Type.
4. WHERE an Event has a non-null Overall_Capacity, THE Reservation_Engine SHALL keep the total confirmed plus reserved units across all of the Event's Ticket_Types at or below the Overall_Capacity.
5. WHEN the Reservation_Engine evaluates availability for a Capped_Type, THE Reservation_Engine SHALL treat available quantity as the smaller of the per-type remaining and the Event's overall remaining capacity.
6. WHEN the Reservation_Engine evaluates availability for a Shared_Pool_Type, THE Reservation_Engine SHALL govern available quantity solely by the Event's overall remaining capacity.
7. WHEN a reservation request would drive a Capped_Type's confirmed plus reserved units above that type's `capacity`, THE Reservation_Engine SHALL reject the whole request and leave all counts unchanged.
8. WHEN a reservation request would drive the Event's confirmed plus reserved units above a non-null Overall_Capacity, THE Reservation_Engine SHALL reject the whole request and leave all counts unchanged.
9. WHEN migrating an existing Ticket_Type, THE Platform SHALL set its Capacity_Mode to `shared_pool` where the Ticket_Type's `capacity` equals the Event's `capacity`, and to `capped` preserving the current `capacity` otherwise.

### Requirement 3: Publish gating for shared-pool without overall capacity

**User Story:** As an Event_Manager, I want the Platform to block publishing when a shared-pool ticket type has no overall ceiling to draw from, so that I never publish an event whose capacity is effectively undefined.

#### Acceptance Criteria

1. IF an Event has at least one Shared_Pool_Type AND a null Overall_Capacity, THEN THE Platform SHALL report a Publish_Blocker via `Event::publishBlockers()` with a message stating that a shared-pool ticket type requires an overall event capacity.
2. WHILE the shared-pool-without-overall-capacity Publish_Blocker is present, THE Platform SHALL leave the Event unpublished when publishing is requested and SHALL keep `Event::isPublishable()` false.
3. THE Platform SHALL surface the shared-pool-without-overall-capacity Publish_Blocker in the Manage_Event_Page readiness checklist alongside the existing blockers.
4. WHEN an Event has no Shared_Pool_Type OR has a non-null Overall_Capacity, THE Platform SHALL NOT report the shared-pool-without-overall-capacity Publish_Blocker.

### Requirement 4: Event location mode and geocoding

**User Story:** As an Event_Manager, I want to set an event as in-person with a mapped address or as online, so that customers get the right location information for each event.

#### Acceptance Criteria

1. THE Platform SHALL give each Event a Location_Mode whose value is either `in_person` or `online`.
2. WHEN an Event_Manager saves an In_Person_Event with an address, THE Platform SHALL Geocode the address via Nominatim synchronously and store the address together with the resulting Coordinates.
3. WHERE an Event_Manager views an In_Person_Event on the Manage_Event_Page, THE Manage_Event_Page SHALL present a Map_Widget with a movable pin, and WHEN the Event_Manager adjusts the pin and saves, THE Platform SHALL persist the adjusted Coordinates.
4. WHEN THE Platform requests a Geocode from Nominatim, THE Platform SHALL send an identifying User-Agent, SHALL serve a previously cached result for an address already resolved rather than issuing a repeat lookup, and SHALL limit the request rate to comply with Nominatim usage policy.
5. IF a Geocode fails or returns no match, THEN THE Platform SHALL save the Event without Coordinates, SHALL inform the Event_Manager that the address could not be located, and SHALL allow the Event_Manager to set the pin manually.
6. WHERE a Customer views the Public_Event_Page for an In_Person_Event with stored Coordinates, THE Public_Event_Page SHALL display a Map_Widget centred on the Coordinates and an "Open in Google Maps" link that deep-links to Google Maps directions for those Coordinates.
7. WHERE a Customer views the Public_Event_Page for an Online_Event, THE Public_Event_Page SHALL omit the Map_Widget and SHALL state that event joining information will be sent by email.
8. WHERE a Customer completes purchase of an Online_Event ticket, THE purchase confirmation SHALL state that event joining information will be sent by email.
9. THE Map_Widget SHALL be keyboard-navigable and SHALL carry an accessible label describing the map.

### Requirement 5: Hero image

**User Story:** As an Event_Manager, I want to upload a hero image for an event, so that the manage-event page and events list present the event with its own banner.

#### Acceptance Criteria

1. WHEN an Event_Manager uploads a Hero_Image via the event form, THE Platform SHALL store the image using the Branding_Upload mechanism and record its path in `events.poster_path`.
2. WHEN an Event_Manager uploads a Hero_Image, THE Platform SHALL accept files of type JPEG, PNG, or WebP up to approximately 4 MB.
3. IF an uploaded Hero_Image is of another type OR exceeds the size limit, THEN THE Platform SHALL reject the upload with a validation message identifying the accepted types and size limit and SHALL leave the stored Hero_Image unchanged.
4. WHERE an Event has a stored Hero_Image, THE Manage_Event_Page SHALL display the Hero_Image at the top of the page and the events list SHALL display the Hero_Image as a banner or thumbnail for that Event.
5. WHERE an Event has a per-Event Hero_Image, THE Platform SHALL use it in preference to the company-level Hero_Image in `companies.poster_path`.
6. WHERE an Event has no per-Event Hero_Image AND the company has a company-level Hero_Image, THE Platform SHALL use the company-level Hero_Image for that Event.
7. THE Platform SHALL NOT enforce specific pixel dimensions on an uploaded Hero_Image.

### Requirement 6: Inline ticket and complimentary-ticket management

**User Story:** As an Event_Manager, I want to create and edit ticket types and issue complimentary tickets inline within the tabbed page, so that I can manage them without leaving the manage-event page for a separate nested page.

#### Acceptance Criteria

1. WHERE an Event_Manager views the Ticket types tab, THE Manage_Event_Page SHALL present Ticket_Type create and edit through inline or modal editing on the same page rather than a separate nested page.
2. WHERE an Event_Manager views the Ticket types tab, THE Manage_Event_Page SHALL present complimentary-ticket issuance through inline or modal editing on the same page.
3. THE Platform SHALL route Ticket_Type create/edit and complimentary-ticket issuance through the existing controllers, routes, and request validation.
4. WHEN a complimentary-ticket issuance is submitted, THE Platform SHALL consume capacity through the Reservation_Engine so the issuance counts against the Ticket_Type and Overall_Capacity and can never oversell.
5. WHEN a complimentary-ticket issuance targets a Shared_Pool_Type, THE Reservation_Engine SHALL enforce the Overall_Capacity for that issuance in the same way as a paid reservation.
6. WHEN a user who lacks `ACTION_MANAGE_EVENTS` submits a Ticket_Type change or a complimentary-ticket issuance, THE Platform SHALL deny the request with an HTTP 403 response.
7. WHEN any user submits a Ticket_Type change or a complimentary-ticket issuance for an Event that belongs to another company, THE Platform SHALL respond with HTTP 404.

### Requirement 7: Preserve capacity and tenancy guarantees

**User Story:** As a Platform operator, I want the existing no-oversell, authorization, and tenancy guarantees preserved through these changes, so that the enhancements do not weaken correctness.

#### Acceptance Criteria

1. THE Platform SHALL keep the Reservation_Engine as the sole enforcement point for Ticket_Type and Event capacity.
2. WHEN capacity is reserved, committed, released, or returned under any Capacity_Mode, THE Reservation_Engine SHALL preserve the availability identity that available quantity equals capacity minus sold minus reserved for a Capped_Type and equals the Overall_Capacity remaining for a Shared_Pool_Type.
3. THE Platform SHALL keep all dashboard event-management actions gated by `ACTION_MANAGE_EVENTS` and all reporting actions gated by `ACTION_VIEW_REPORTS`.
4. WHEN any user requests any dashboard action or page introduced or modified by this feature for an Event that belongs to another company, THE Platform SHALL respond with HTTP 404.
