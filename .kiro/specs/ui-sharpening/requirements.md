# Requirements Document

## Introduction

This feature is a purely presentational and copy refactor of an existing multi-tenant Laravel/Blade event-ticketing platform. The visual direction is "sharp everywhere": square corners, bolder borders and weights, and tighter spacing across the client-facing dashboard and the public booking pages. The refactor is WCAG-informed (text contrast, visible focus, keyboard operability, adequate touch target size) and keeps the existing colour scheme (main platform brand blue `#0f16c4`; storefront per-company brand-colour override intact, default `#674df3`).

The primary focus is the client-facing platform, especially creating and managing events (event create/edit forms and the tabbed manage-event page). The scope also adds a mobile sticky "Book now" bar on the public event page and cleans up user-facing copy (removing em dashes, filler, and hedging/marketing-speak).

This change touches only Blade views (markup and copy) and CSS. No routes, controllers, request validation, data model, authorization, tenancy, or capacity/reservation logic are modified.

## Grounding Baseline (existing system, confirmed by review)

- **Two CSS systems exist.** The shared public stylesheet `public/css/app.css` defines `--brand: #0f16c4` and `--brand-dark: #0a0f99` and applies non-zero `border-radius` values across components (for example `.btn` `0.5rem`, `.card` `0.75rem`, `.field` inputs/textareas `0.5rem`, `.checkout-card` `1rem`, `.auth-card` `0.75rem`, tables `~0.5rem`, tabs `~0.375rem`, panels `~0.75rem`, and pill/badge chips `999px`). The storefront layout `resources/views/layouts/storefront.blade.php` carries its own inline `<style>` with radii around `12–14px` and its own `--brand` default `#674df3`.
- **Public event page.** `resources/views/events/show.blade.php` and `resources/views/events/_ticket_row.blade.php` render the buying flow. The layout uses an `.event-layout` grid with an `.event-checkout` aside that is set to `order: -1` on mobile and made sticky at viewport width `>= 860px`. The `.checkout-card` (heading "Get tickets") holds ticket selection and the "Continue to payment" submit control.
- **Manage-event page.** Reorganised into tabs (Overview, Ticket types, Location, Share & QR, Report, Orders) with a readiness checklist aside by the existing `event-experience-polish` spec. Event create/edit and manage views live under `resources/views/events/` and `resources/views/dashboard/`.
- **Layouts.** `resources/views/layouts/dashboard.blade.php` (dark header plus sidebar), `resources/views/layouts/storefront.blade.php`, and `resources/views/layouts/app.blade.php`.
- **Pill/badge elements.** `.pill`, `.admin-pill`, and `.role-badge` currently use `border-radius: 999px`.

## Glossary

- **UI_Sharpening**: The overall presentational refactor delivered by this feature, comprising CSS and Blade markup/copy changes only.
- **Shared_Design_System**: The shared public stylesheet `public/css/app.css` and the component rules it defines.
- **Storefront_Layout**: The layout `resources/views/layouts/storefront.blade.php` and its inline `<style>` block, including the per-company brand-colour override mechanism.
- **Event_Authoring_Views**: The event create, edit, and tabbed manage-event Blade views under `resources/views/events/` and `resources/views/dashboard/`.
- **Public_Event_Page**: The public event page rendered by `resources/views/events/show.blade.php` and `resources/views/events/_ticket_row.blade.php`.
- **Checkout_Card**: The `.checkout-card` element on the Public_Event_Page that contains ticket selection and the "Continue to payment" submit control.
- **Mobile_Book_Now_Bar**: A new sticky bottom bar shown on the Public_Event_Page at mobile viewport widths, displaying price and a "Book now" control that moves focus and scroll to the Checkout_Card.
- **Status_Chip**: A pill or badge element (`.pill`, `.admin-pill`, `.role-badge`) used to convey status.
- **Brand_Colour**: The main platform brand blue `#0f16c4` in the Shared_Design_System, and the per-company brand colour on the Storefront_Layout (default `#674df3`).
- **Square_Corners**: A `border-radius` of `0` applied to a component.
- **Visible_Focus_State**: A focus indicator that is perceivable against its background for keyboard users on interactive elements.
- **User_Facing_Copy**: Text rendered to end users in Event_Authoring_Views and the booking pages, excluding CSS comments and code comments.

## Requirements

### Requirement 1: Global sharpening of the shared design system

**User Story:** As a platform user, I want a consistently sharp, high-contrast interface across shared components, so that the product feels crisp and deliberate.

#### Acceptance Criteria

1. THE Shared_Design_System SHALL apply Square_Corners to `.btn`, `.card`, `.field` inputs, `.field` textareas, `.field` selects, `.checkout-card`, `.auth-card`, table containers, tab controls, and panel containers.
2. THE Shared_Design_System SHALL retain the existing Brand_Colour values `--brand: #0f16c4` and `--brand-dark: #0a0f99`.
3. THE Shared_Design_System SHALL render component borders and heading weights at or above their current visual weight to reinforce the sharpened direction.
4. THE Shared_Design_System SHALL reduce component spacing to a tighter scale while preserving readability at a minimum tap-friendly control height.
5. WHEN an interactive element receives keyboard focus, THE Shared_Design_System SHALL render a Visible_Focus_State with a colour contrast ratio of at least 3:1 against adjacent colours.
6. THE Shared_Design_System SHALL render body and control text with a colour contrast ratio of at least 4.5:1, and large text at a ratio of at least 3:1, against their backgrounds.
7. WHERE a Status_Chip (`.pill`, `.admin-pill`, `.role-badge`) is rendered, THE Shared_Design_System SHALL keep the fully rounded `border-radius: 999px` treatment so that status chips remain visually distinct from squared structural components.

### Requirement 2: Event create, edit, and manage-event sharpening

**User Story:** As an event organiser, I want the event create, edit, and manage screens to look slicker and be tighter to scan, so that authoring and managing an event feels fast and clear.

#### Acceptance Criteria

1. THE Event_Authoring_Views SHALL apply Square_Corners to form fields, cards, panels, and buttons rendered within the event create, edit, and manage-event screens.
2. THE Event_Authoring_Views SHALL preserve the existing tab set of the manage-event page: Overview, Ticket types, Location, Share & QR, Report, and Orders.
3. THE Event_Authoring_Views SHALL preserve the readiness checklist aside on the manage-event page.
4. THE Event_Authoring_Views SHALL tighten vertical and horizontal spacing between form fields, sections, and cards while keeping labels associated with their controls.
5. WHEN a field, tab, or button in the Event_Authoring_Views receives keyboard focus, THE Event_Authoring_Views SHALL render a Visible_Focus_State with a colour contrast ratio of at least 3:1 against adjacent colours.
6. THE Event_Authoring_Views SHALL preserve every form field name, submit action, and route target present before this feature.

### Requirement 3: Storefront and public event page sharpening with preserved brand override

**User Story:** As a buyer, I want the storefront and public event page to feel sharp and on-brand for the seller, so that the buying experience looks trustworthy and current.

#### Acceptance Criteria

1. THE Storefront_Layout SHALL apply Square_Corners to its inline-styled components in place of the existing `12–14px` radii.
2. THE Storefront_Layout SHALL preserve the per-company Brand_Colour override mechanism, including the default value `#674df3`.
3. WHEN a company brand colour is configured, THE Storefront_Layout SHALL render storefront and Public_Event_Page brand accents using that configured colour.
4. THE Public_Event_Page SHALL apply Square_Corners to the Checkout_Card, ticket rows, and buttons.
5. THE Public_Event_Page SHALL preserve the `.event-layout` grid, the `.event-checkout` `order: -1` mobile ordering, and the sticky checkout behaviour at viewport width `>= 860px`.
6. WHEN the Public_Event_Page is rendered, THE Public_Event_Page SHALL render text against its brand-derived backgrounds with a colour contrast ratio of at least 4.5:1 for body text and at least 3:1 for large text.

### Requirement 4: Mobile sticky "Book now" bar

**User Story:** As a buyer on a phone, I want a persistent way to jump to the tickets, so that I never have to hunt for where to buy.

#### Acceptance Criteria

1. WHILE the Public_Event_Page is viewed at a mobile viewport width, THE Public_Event_Page SHALL render the Mobile_Book_Now_Bar fixed to the bottom of the viewport.
2. THE Mobile_Book_Now_Bar SHALL display the event ticket price and a "Book now" control.
3. WHEN the buyer activates the "Book now" control, THE Mobile_Book_Now_Bar SHALL move scroll position and keyboard focus to the Checkout_Card.
4. WHILE the Checkout_Card submit control is within the viewport, THE Mobile_Book_Now_Bar SHALL hide itself so that the Checkout_Card submit control is not obscured.
5. THE Mobile_Book_Now_Bar SHALL expose an accessible name for its "Book now" control to assistive technology.
6. THE Mobile_Book_Now_Bar SHALL be operable by keyboard and SHALL render a Visible_Focus_State on its "Book now" control with a colour contrast ratio of at least 3:1 against adjacent colours.
7. THE Mobile_Book_Now_Bar SHALL render its "Book now" control with a touch target of at least 44 by 44 CSS pixels.
8. WHILE the Public_Event_Page is viewed at a viewport width of at least 860 pixels, THE Public_Event_Page SHALL hide the Mobile_Book_Now_Bar.

### Requirement 5: User-facing copy cleanup

**User Story:** As a reader of the product, I want short, direct, confident copy, so that instructions and marketing read cleanly and professionally.

#### Acceptance Criteria

1. THE Event_Authoring_Views and Public_Event_Page SHALL render User_Facing_Copy with no em dash (`—`) characters.
2. THE Event_Authoring_Views and Public_Event_Page SHALL render User_Facing_Copy with filler phrasing removed, including "in order to", "simply", and "seamlessly".
3. THE Event_Authoring_Views and Public_Event_Page SHALL render User_Facing_Copy free of "AI" promotional references.
4. THE Event_Authoring_Views and Public_Event_Page SHALL render hedging and marketing-speak rewritten into short, direct, active-voice statements.
5. WHERE copy cleanup is applied, THE UI_Sharpening SHALL leave CSS comments and code comments unchanged, since those are not User_Facing_Copy.

### Requirement 6: Preserved behaviour and accessibility guarantees

**User Story:** As a platform operator, I want the refactor to change only appearance and copy, so that existing functionality, security, and access remain intact.

#### Acceptance Criteria

1. THE UI_Sharpening SHALL leave all application routes unchanged.
2. THE UI_Sharpening SHALL leave all controllers, request validation rules, and the data model unchanged.
3. THE UI_Sharpening SHALL leave all authorization, tenancy scoping, and capacity/reservation logic unchanged.
4. THE UI_Sharpening SHALL preserve keyboard operability for every interactive element that was keyboard-operable before this feature.
5. IF a change would alter application behaviour beyond presentation and User_Facing_Copy, THEN THE UI_Sharpening SHALL exclude that change from scope.
6. THE UI_Sharpening SHALL confine its edits to Blade view markup, User_Facing_Copy, and CSS.
