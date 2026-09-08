# Requirements Document

## Introduction

This feature redesigns the event authoring and management experience of the Laravel event ticketing platform. It replaces the current minimal event-creation screen with a guided multi-step wizard, introduces an inline accordion component for ticket types, converts dashboard table rows into whole-row navigation targets, makes both the main dashboard sidebar and the per-event section sub-sidebar collapsible, and replaces emoji icons with accessible inline SVG icons throughout the authoring and management surfaces. It also simplifies ticket configuration terminology by introducing a three-way "Availability" control (Limited quantity, Use event capacity, Unlimited) and a grouped "Sales period" control with sensible defaults, backed by two new database columns.

The redesign preserves existing tenant scoping, permission gates, free/£0 ticket support, capacity advisory semantics, and the recently sharpened design tokens, and introduces no new frontend build step (plain Blade plus vanilla JavaScript).

## Glossary

- **Authoring_UI**: The set of dashboard screens used to create and manage events, including the create wizard, per-event manage screens, and ticket-type editing.
- **Create_Wizard**: The multi-step guided interface used to create a new event. Used for creation only, not for later edits.
- **Manage_Screen**: The per-event management page reached after event creation, containing per-section screens (details, venue, tickets, branding, sponsors, etc.).
- **Section_Nav**: The per-event secondary navigation currently rendered as `.section-nav` in `_nav.blade.php`.
- **Main_Sidebar**: The primary dashboard navigation defined in `layouts/dashboard`.
- **Ticket_Accordion**: The inline expandable component used to list and edit ticket types on the manage screen and within the Create_Wizard ticket step.
- **Ticket_Type**: A category of ticket within an event (`app/Models/TicketType.php`), with name, price, availability, and sales period.
- **Availability_Control**: The three-way radio control that sets a Ticket_Type's `capacity_mode` (Limited quantity, Use event capacity, Unlimited).
- **Sales_Period_Control**: The grouped control (two default-checked checkboxes plus conditional date/time inputs) that sets `sale_starts_at` and `sale_ends_at`.
- **Capacity_Mode**: The `ticket_types.capacity_mode` value; one of `capped`, `shared_pool`, or the new `unlimited`.
- **Location_Map**: The reusable Leaflet draggable-map with address geocoding defined in `_location.blade.php`.
- **Publish_Action**: The control that publishes an event, placed along the top of the Manage_Screen.
- **Dashboard_Table**: A dashboard index table (events, customers, and similar) presenting rows that navigate to a primary destination.
- **Design_Tokens**: The shared CSS variables in `public/css/app.css` (for example `--radius`, border weight, focus-visible ring).

## Requirements

### Requirement 1: Whole-row navigation in dashboard tables

**User Story:** As a dashboard user, I want to click anywhere on a table row to open its primary destination, so that I can navigate quickly without aiming for a small text link.

#### Acceptance Criteria

1. WHEN a user activates a row in a Dashboard_Table, THE Authoring_UI SHALL navigate to that row's primary destination.
2. THE Authoring_UI SHALL apply whole-row navigation to the events list and the customers list.
3. WHEN a row contains a nested action control, THE Authoring_UI SHALL allow the user to operate that nested action without triggering the row's primary navigation.
4. THE Authoring_UI SHALL render a visible hover affordance on a Dashboard_Table row that indicates the row is activatable.
5. THE Authoring_UI SHALL make each Dashboard_Table row reachable by keyboard.
6. WHEN a Dashboard_Table row has keyboard focus and the user presses Enter, THE Authoring_UI SHALL navigate to that row's primary destination.
7. THE Authoring_UI SHALL render a visible focus-visible indicator on a Dashboard_Table row that has keyboard focus, using the existing Design_Tokens.

### Requirement 2: Collapsible sidebars on the manage-event screen

**User Story:** As an event organizer, I want to collapse the navigation sidebars, so that I can maximize the working area on the manage screen.

#### Acceptance Criteria

1. THE Authoring_UI SHALL render the Section_Nav as a collapsible secondary sidebar on the Manage_Screen.
2. THE Authoring_UI SHALL render the Main_Sidebar as collapsible.
3. WHEN a user activates the collapse control for a sidebar on a desktop viewport, THE Authoring_UI SHALL collapse that sidebar and expand the available working area.
4. WHEN a user activates the expand control for a collapsed sidebar, THE Authoring_UI SHALL restore that sidebar to its expanded state.
5. WHEN a user sets the collapse state of a sidebar, THE Authoring_UI SHALL persist that state and apply it on the user's subsequent visits.
6. WHILE the viewport is a mobile width, THE Authoring_UI SHALL present sidebar navigation in a form usable on that width.
7. THE Authoring_UI SHALL make each sidebar collapse control operable by keyboard and label it for screen readers.

### Requirement 3: Inline SVG icons replacing emoji

**User Story:** As an event organizer, I want professional vector icons instead of emoji, so that the authoring interface looks consistent and polished.

#### Acceptance Criteria

1. THE Authoring_UI SHALL render Section_Nav icons as inline SVG icons.
2. THE Authoring_UI SHALL replace each emoji icon in the authoring and management surfaces, including the Section_Nav, status flags, and meta indicators, with an inline SVG icon.
3. THE Authoring_UI SHALL render each inline SVG icon without introducing a new frontend build dependency.
4. THE Authoring_UI SHALL mark each decorative inline SVG icon as hidden from assistive technology.
5. THE Authoring_UI SHALL present a text label adjacent to each inline SVG icon that conveys navigational or status meaning.

### Requirement 4: Multi-step event creation wizard

**User Story:** As an event organizer, I want a guided step-by-step flow to create a new event, so that I can complete setup without confusion and with contextual help at each stage.

#### Acceptance Criteria

1. WHEN a user starts creating a new event, THE Create_Wizard SHALL present the creation flow as ordered steps for basic details, event date and time, venue, ticket types, branding, and an optional sponsors step.
2. THE Create_Wizard SHALL collect an event name and an event description in the basic details step.
3. THE Create_Wizard SHALL provide a date and time selector for the event date and time in the date-and-time step.
4. THE Create_Wizard SHALL present the Location_Map with address geocoding for an in-person venue and an option to mark the event as online in the venue step.
5. THE Create_Wizard SHALL allow the user to add multiple ticket types using the Ticket_Accordion in the ticket types step.
6. THE Create_Wizard SHALL allow the user to upload a header image and choose between a separate event logo and the account default logo in the branding step.
7. WHERE a user indicates they have sponsors, THE Create_Wizard SHALL present sponsor detail fields reusing the existing sponsor fields.
8. WHERE a step is optional, THE Create_Wizard SHALL allow the user to skip that step.
9. WHEN a user advances from a step, THE Create_Wizard SHALL validate that step's inputs before proceeding.
10. IF a step's inputs fail validation, THEN THE Create_Wizard SHALL keep the user on that step and present a message identifying each invalid input.
11. THE Create_Wizard SHALL allow the user to move backward to a previously completed step and forward again.
12. THE Create_Wizard SHALL present contextual help content on each step.
13. WHEN a user completes the Create_Wizard, THE Authoring_UI SHALL create the event and navigate the user to the Manage_Screen for that event.
14. WHERE the active user lacks the settings permission, THE Create_Wizard SHALL restrict access to the branding and sponsors steps in accordance with the existing permission gate.
15. THE Create_Wizard SHALL create events scoped to the active tenant.

### Requirement 5: Publish action on the manage screen

**User Story:** As an event organizer, I want the Publish button along the top of the manage page, so that I can publish an event without hunting through sections.

#### Acceptance Criteria

1. THE Authoring_UI SHALL render the Publish_Action along the top of the Manage_Screen.
2. WHEN a user activates the Publish_Action for an unpublished event, THE Authoring_UI SHALL publish that event.
3. WHILE an event is published, THE Authoring_UI SHALL indicate the published state on the Manage_Screen.
4. THE Authoring_UI SHALL restrict the Publish_Action to users permitted to publish events under the existing permission gate.

### Requirement 6: Inline accordion ticket-type component

**User Story:** As an event organizer, I want to manage ticket types in an inline accordion, so that I can review and edit each type in place without leaving the list.

#### Acceptance Criteria

1. THE Ticket_Accordion SHALL render an "Add ticket type" control above the list of ticket types.
2. WHEN a user activates the "Add ticket type" control, THE Ticket_Accordion SHALL add a new expandable ticket-type entry using the same component.
3. THE Ticket_Accordion SHALL render each collapsed ticket-type summary row with the ticket name, the price, the remaining-of-total capacity, the sales status, and a chevron indicator.
4. WHEN a user activates a collapsed ticket-type row, THE Ticket_Accordion SHALL expand that row's editing form inline within the row.
5. WHILE one ticket-type row is expanded and a user expands another row, THE Ticket_Accordion SHALL collapse the previously expanded row so that only one row is expanded at a time.
6. THE Ticket_Accordion SHALL present, in an expanded row, fields for ticket name, price, description, the Availability_Control, and the Sales_Period_Control, along with a save control and a cancel control.
7. WHEN a user activates the save control in an expanded row, THE Ticket_Accordion SHALL persist that ticket type's values.
8. WHEN a user activates the cancel control in an expanded row, THE Ticket_Accordion SHALL discard unsaved changes for that row and return the row to its collapsed summary.
9. THE Ticket_Accordion SHALL accept a price of zero as a free ticket.
10. WHILE the viewport is a desktop width, THE Ticket_Accordion SHALL present the expanded editing fields in a compact two-column layout.
11. THE Ticket_Accordion SHALL make each summary row and each editing control operable by keyboard.

### Requirement 7: Availability control

**User Story:** As an event organizer, I want a plain-language availability choice for each ticket type, so that I can set how many can be sold without learning internal terminology.

#### Acceptance Criteria

1. THE Availability_Control SHALL present three options labeled "Limited quantity", "Use event capacity", and "Unlimited".
2. WHEN a user selects "Limited quantity", THE Availability_Control SHALL present a quantity input and THE Authoring_UI SHALL set the Ticket_Type Capacity_Mode to `capped` with the entered quantity as the capacity.
3. WHEN a user selects "Use event capacity", THE Authoring_UI SHALL set the Ticket_Type Capacity_Mode to `shared_pool`.
4. WHEN a user selects "Unlimited", THE Authoring_UI SHALL set the Ticket_Type Capacity_Mode to `unlimited`.
5. THE Authoring_UI SHALL replace the former "Capacity mode" select control with the Availability_Control in the ticket-type editing form.
6. WHILE a Ticket_Type Capacity_Mode is `unlimited`, THE Authoring_UI SHALL present that Ticket_Type's capacity advisory consistently with the existing capacity comparison semantics.

### Requirement 8: Sales period control

**User Story:** As an event organizer, I want sensible default sales timing, so that a standard ticket needs no date entry while I can still set custom dates when required.

#### Acceptance Criteria

1. THE Sales_Period_Control SHALL present a checkbox labeled "Start selling when the event goes live" that is checked by default.
2. THE Sales_Period_Control SHALL present a checkbox labeled "Stop selling when the event starts" that is checked by default.
3. WHILE the "Start selling when the event goes live" checkbox is checked, THE Authoring_UI SHALL set the Ticket_Type `sale_starts_at` to null.
4. WHILE the "Stop selling when the event starts" checkbox is checked, THE Authoring_UI SHALL set the Ticket_Type `sale_ends_at` to null.
5. WHEN a user unchecks the "Start selling when the event goes live" checkbox, THE Sales_Period_Control SHALL present a start date and time input.
6. WHEN a user unchecks the "Stop selling when the event starts" checkbox, THE Sales_Period_Control SHALL present an end date and time input.
7. WHILE a Ticket_Type `sale_starts_at` is null, THE Authoring_UI SHALL treat the ticket type as on sale from the moment the event is published.
8. WHILE a Ticket_Type `sale_ends_at` is null, THE Authoring_UI SHALL treat the ticket type as on sale until the event start time.
9. THE Authoring_UI SHALL allow a ticket type to be saved without any sales date entry when both default checkboxes remain checked.

### Requirement 9: Ticket-type schema additions

**User Story:** As a developer, I want the database schema to support ticket descriptions and the unlimited availability option, so that the new authoring controls can persist their values.

#### Acceptance Criteria

1. THE Authoring_UI SHALL store an optional description text value for a Ticket_Type in a `ticket_types.description` column.
2. THE Authoring_UI SHALL accept `unlimited` as a valid Ticket_Type Capacity_Mode value in the `ticket_types.capacity_mode` column.
3. THE Authoring_UI SHALL provide a Laravel migration that adds the `ticket_types.description` column and extends the `capacity_mode` values to include `unlimited`.
4. THE Authoring_UI SHALL provide raw SQL files under `database/sql/` for these schema changes following the existing numbered naming convention continuing after `035`.
5. WHERE a Ticket_Type has no description, THE Authoring_UI SHALL treat the description as absent.

### Requirement 10: Authoring experience polish

**User Story:** As an event organizer, I want a compact interface with inline guidance, so that authoring feels efficient rather than sparse and confusing.

#### Acceptance Criteria

1. THE Authoring_UI SHALL present inline help content within the authoring and management screens.
2. THE Authoring_UI SHALL apply the existing Design_Tokens to authoring and management components.
3. THE Authoring_UI SHALL preserve tenant scoping across the authoring and management screens.
4. THE Authoring_UI SHALL preserve the existing permission gates for branding and sponsors editing.
5. THE Authoring_UI SHALL support free tickets priced at zero across the authoring screens.
6. THE Authoring_UI SHALL provide keyboard operability, focus-visible indication, and screen-reader labels across the authoring and management components.
