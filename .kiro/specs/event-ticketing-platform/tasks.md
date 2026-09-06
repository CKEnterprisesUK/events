# Implementation Plan: Event Ticketing Platform

## Overview

This plan builds the multi-tenant Laravel (PHP) + MySQL ticketing platform incrementally, in working/demoable slices, on shared cPanel hosting. It starts from the project foundation and tenancy, layers in users/roles, events/ticket types, capacity and money engines, branding, the public storefront and checkout, Stripe Connect + Checkout (mocked in tests), webhooks, fulfilment (QR + email), scanning, refunds, comps, the super-admin dashboard, accountant reporting, GDPR, and finally the hosting/deployment wiring.

Correctness is driven primarily by property-based tests (PBT) using a PHP PBT library such as **Eris** (never hand-rolled generators), each running a **minimum of 100 iterations**, one PBT per design property, tagged with:
`// Feature: event-ticketing-platform, Property {number}: {property text}`
Feature tests (Laravel HTTP + database) and a couple of smoke tests complement the PBTs where the design's Testing Strategy calls for them. **Stripe is always mocked in automated tests; card data never appears.**

## Tasks

- [x] 1. Laravel project foundation and app scaffold
  - [x] 1.1 Scaffold the Laravel app, MySQL connection, and database queue tables
    - Create/confirm the Laravel application skeleton and configure the MySQL connection in `config/database.php` / `.env` for the single shared database
    - Set `QUEUE_CONNECTION=database`; run `queue:table` and `queue:failed-table` and add migrations so `jobs` and `failed_jobs` exist
    - Install the Eris (or equivalent) PHP PBT dev dependency and wire the test suite (PHPUnit) to use a MySQL test database so `SELECT ... FOR UPDATE` behaviour is real
    - Also generate and commit the matching versioned raw SQL file(s) under `database/sql/` (e.g. `NNN_create_queue_tables.sql`) for the `jobs` and `failed_jobs` tables from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`), including the `migrations` table; keep the SQL byte-consistent with the migrations (regenerate on any change)
    - _Requirements: 15.1, 15.4_
    - _Design: Key Technology Decisions, Jobs (DB Queue), Testing Strategy, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 1.2 Auth scaffolding, base layout, and landing page route
    - Add authentication scaffolding for Company_Users (login/logout/session), a base Blade layout, and the root `LandingController` serving `GET /`
    - _Requirements: 8.1_
    - _Design: LandingController (8.1)_

  - [x] 1.3 Feature test for landing page rendering
    - Assert `GET /` returns 200 and renders the landing page
    - _Requirements: 8.1_

- [x] 2. Companies, tenancy resolution, and global scope
  - [x] 2.1 Create companies table, TenantContext, and slug validation
    - Add the `companies` migration/model with all design columns (`slug` UNIQUE, `status` enum, `fee_handling_mode` default `absorb`, `company_fee_percent` NULL, `stripe_account_id`, `stripe_charges_enabled`, `currency`, branding/terms/`ticket_field_defs`)
    - Implement `TenantContext` holding the resolved Company and exposing `companyId()`
    - Implement slug validation: 1–255 chars, lowercase alphanumeric + hyphens only, unique across Companies
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_companies.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - _Requirements: 1.1, 1.6, 2.x (status), 13.1, 13.2_
    - _Design: companies data model, TenantContext, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 2.2 Write property test for slug validity
    - **Property 3: Slug validity** — accept iff 1–255 chars, lowercase alphanumeric + hyphens, and not already used
    - **Validates: Requirements 1.6**
    - `// Feature: event-ticketing-platform, Property 3: ...` — Eris, min 100 iterations

  - [x] 2.3 Implement ResolveTenant and EnforceTenantScope middleware with global company_id scope
    - `ResolveTenant`: resolve active Company by case-insensitive slug within the 200ms budget (indexed lookup / short-lived cache), 404 when unresolved, bind `company_id`; also 404 when the resolved Company is suspended
    - `EnforceTenantScope`: register the global Eloquent `company_id` scope for the request lifecycle and clear it afterward; reserved prefixes (`/`, `/dashboard`, `/admin`) skip slug resolution and establish no Company
    - _Requirements: 1.2, 1.3, 1.4, 1.5, 1.7, 2.1, 2.2_
    - _Design: ResolveTenant, EnforceTenantScope, Request and Tenancy Flow_

  - [x] 2.4 Write property test for tenant isolation
    - **Property 1: Tenant isolation** — reads/writes/updates/deletes affect only the resolved `company_id`; cross-Company access denied and leaves the record byte-for-byte unchanged (incl. scan lookups and GDPR ops)
    - **Validates: Requirements 1.4, 1.5, 3.8, 3.10, 16.6, 22.5**
    - `// Feature: event-ticketing-platform, Property 1: ...` — Eris, min 100 iterations

  - [x] 2.5 Write property test for unresolved/missing tenant
    - **Property 2: Unresolved / missing tenant establishes no Company** — non-matching leading segment → no Company + 404 for slug paths + deny Company-owned records; valid slug resolves the same Company in any letter-case
    - **Validates: Requirements 1.2, 1.3, 1.7**
    - `// Feature: event-ticketing-platform, Property 2: ...` — Eris, min 100 iterations

- [x] 3. Company suspension gating and reversibility
  - [x] 3.1 Enforce suspension across storefront, events, sales, and login
    - Wire `ResolveTenant` to 404 suspended Companies' storefront/event pages; add `EnsureCompanyActive` auth hook blocking login/session for users of a suspended Company; prevent suspended Companies from selling tickets
    - _Requirements: 2.1, 2.2, 2.3, 2.4_
    - _Design: EnsureCompanyActive (2.3, 2.4)_

  - [x] 3.2 Write property test for suspension gating and reversibility
    - **Property 4: Suspension gating and reversibility** — storefront/event/sales/login available iff not suspended; suspend→unsuspend restores state equivalent to a never-suspended active Company
    - **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5**
    - `// Feature: event-ticketing-platform, Property 4: ...` — Eris, min 100 iterations

- [x] 4. Users, roles, single-Owner invariant, and session timeout
  - [x] 4.1 Create users table, four-role model, and RoleService with single-Owner invariant
    - Add the `users` migration/model (`company_id` NULL, `is_super_admin`, `role` enum, `last_activity_at`, partial unique index enforcing one `owner` per `company_id`)
    - Implement `RoleService` enforcing exactly four roles and the single-Owner invariant on assign/remove/demote
    - Implement `RoleAuthorization` policies gating each action by role; deny with an authorisation error and leave data unchanged; scope authenticated sessions to the user's own Company; reject invalid credentials
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_users.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 3.10_
    - _Design: RoleService, RoleAuthorization/policies, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 4.2 Implement SessionTimeout middleware (30-minute idle)
    - Invalidate sessions idle ≥30 minutes using `last_activity_at`, forcing re-auth before any further action
    - _Requirements: 3.11_
    - _Design: SessionTimeout (3.11)_

  - [x] 4.3 Write property test for owner uniqueness invariant
    - **Property 5: Owner uniqueness invariant** — across add/invite-accept/role-change/removal, always exactly one Owner; violating operations rejected and leave the Owner unchanged
    - **Validates: Requirements 3.2, 4.5**
    - `// Feature: event-ticketing-platform, Property 5: ...` — Eris, min 100 iterations

  - [x] 4.4 Write property test for role permission matrix
    - **Property 6: Role permission matrix** — authorise (role, action) iff action is in that role's permitted set; denied actions leave data unchanged
    - **Validates: Requirements 3.3, 3.4, 3.5, 3.6, 3.7, 20.7, 21.1, 21.2**
    - `// Feature: event-ticketing-platform, Property 6: ...` — Eris, min 100 iterations

  - [x] 4.5 Write property test for session idle timeout boundary
    - **Property 7: Session idle timeout boundary** — require re-auth iff idle duration ≥ 30 minutes (generate durations around the boundary)
    - **Validates: Requirements 3.11**
    - `// Feature: event-ticketing-platform, Property 7: ...` — Eris, min 100 iterations

  - [x] 4.6 Write feature tests for four-role enum and invalid credentials
    - Assert exactly four roles are supported (3.1) and that invalid credentials are rejected with an invalid-credentials error and no session (3.9)
    - _Requirements: 3.1, 3.9_

- [x] 5. User invitations and management
  - [x] 5.1 Implement invitations table and InvitationController flows
    - Add the `invitations` migration/model (`company_id`, `email`, `role` enum excluding owner, `token`, `accepted_at`, `expires_at`)
    - Implement invite-by-email (create invitation for Owner's Company + role), accept (create Company_User scoped to inviting Company), role change, and removal; enforce assigned role ∈ {Admin, Accountant, Scanner} and route Owner-removal/demotion through the single-Owner invariant
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_invitations.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_
    - _Design: InvitationController, RoleService, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 5.2 Write feature tests for invitation flows
    - Cover accept creates scoped user (4.2), role change updates permissions (4.3), removal revokes access (4.4), and Owner-not-invitable (4.6)
    - _Requirements: 4.2, 4.3, 4.4, 4.6_

- [x] 6. Events and ticket types management
  - [x] 6.1 Create events table and EventController (create/update/publish)
    - Add the `events` migration/model (`company_id`, details, `capacity` NULL, `is_published`, branding overrides) and CRUD scoped to the Company; publishing makes the event page available; unpublished events block Customer view/purchase
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_events.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_
    - _Design: EventController, events data model, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 6.2 Create ticket_types table and TicketTypeController with field/sale-window validation
    - Add the `ticket_types` migration/model (`company_id`, `event_id`, `name`, `price_minor`, `capacity`, `sold_count`, `reserved_count`, `sale_starts_at`, `sale_ends_at`) with money as integer minor units
    - Validate name 1–100 chars, price 0.00–999,999.99, capacity 1–1,000,000, 1–50 types per event, and sale-window end strictly after start; treat price 0 as free
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_ticket_types.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - _Requirements: 6.1, 6.2, 6.3, 6.9_
    - _Design: ticket_types data model, TicketTypeController, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 6.3 Write property test for ticket-type field and sale-window validation
    - **Property 9: Ticket-type field and sale-window validation** — accept iff name 1–100, price 0.00–999,999.99, capacity 1–1,000,000, end strictly after start; accepted inputs round-trip
    - **Validates: Requirements 6.1, 6.9**
    - `// Feature: event-ticketing-platform, Property 9: ...` — Eris, min 100 iterations

  - [x] 6.4 Write property test for publish and sale-window gating
    - **Property 8: Publish and sale-window gating** — Customer may view/purchase a Ticket_Type iff Event published and now ∈ [sale start, sale end)
    - **Validates: Requirements 5.5, 6.4, 6.5**
    - `// Feature: event-ticketing-platform, Property 8: ...` — Eris, min 100 iterations

- [x] 7. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 8. Capacity / reservation service
  - [x] 8.1 Implement CapacityReservationService with FOR UPDATE and 900s window
    - Reserve/release capacity inside a DB transaction using `SELECT ... FOR UPDATE` on ticket-type rows and the event capacity row so concurrent checkouts serialize and never oversell; reject over-requests atomically (reserve nothing); enforce the 900s reservation window; release on expiry/cancel/failure
    - Remaining available = `capacity - sold_count - active_reserved`, applied against Ticket_Type and overall Event capacity
    - _Requirements: 5.6, 6.6, 6.7, 6.8, 10.6, 10.7, 18.3_
    - _Design: CapacityReservationService_

  - [x] 8.2 Implement ReleaseExpiredReservationsJob (scheduled)
    - Scheduled job releasing reservations past the 900s window, restoring available capacity; make release idempotent
    - _Requirements: 10.7, 10.12_
    - _Design: ReleaseExpiredReservationsJob_

  - [x] 8.3 Write property test for no oversell under concurrency
    - **Property 10: No oversell under concurrency** — across any interleaving of concurrent reserve/purchase/comp requests, committed+active-reserved never exceeds Ticket_Type (nor Event) capacity; over-requests rejected in full, prior counts unchanged (run against real MySQL FOR UPDATE with randomized interleavings)
    - **Validates: Requirements 5.6, 6.6, 6.7, 6.8, 10.6, 18.3**
    - `// Feature: event-ticketing-platform, Property 10: ...` — Eris, min 100 iterations

  - [x] 8.4 Write property test for reservation release restores availability
    - **Property 11: Reservation release restores availability** — on non-completion within 900s or failure/cancel, reserved capacity is released back to the pre-reservation value
    - **Validates: Requirements 10.7, 10.12**
    - `// Feature: event-ticketing-platform, Property 11: ...` — Eris, min 100 iterations

- [x] 9. Fee calculation engine and platform/company settings
  - [x] 9.1 Create platform_settings and add company fee fields + fee handling mode
    - Add the `platform_settings` migration/model (`global_fee_percent`); confirm `companies.company_fee_percent` and `fee_handling_mode` (default `absorb`) usage; allow Owner to set Fee_Handling_Mode
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_platform_settings.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - Also generate and commit a seed SQL file under `database/sql/` (e.g. `NNN_seed_platform_settings.sql`) inserting the single default `platform_settings` row with the default `global_fee_percent`
    - _Requirements: 13.1, 13.2, 13.3, 20.5, 20.6_
    - _Design: platform_settings data model, companies data model, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 9.2 Implement FeeCalculationService (selection, clamp, round-half-up, Order_Total, free orders)
    - Pure deterministic engine in integer minor units: effective percent = Company override else Global; Application_Fee = subtotal × percent, round-half-up, clamped to `[0, subtotal]`; Absorb → Order_Total = subtotal, Booking_Fee = 0; Pass_On → Booking_Fee = Application_Fee, Order_Total = subtotal + Booking_Fee; free-only → all money zero regardless of mode; snapshot fee mode per order
    - _Requirements: 12.1, 12.2, 12.3, 12.4, 13.4, 13.5, 13.7, 13.8_
    - _Design: FeeCalculationService_

  - [x] 9.3 Write property test for application fee computation, selection, clamping, and rounding
    - **Property 16: Application fee computation, selection, clamping, and rounding** — fee = subtotal × effective percent, round-half-up, clamped `[0, subtotal]`; `application_fee_amount` equals this
    - **Validates: Requirements 12.2, 12.3, 12.4, 20.5, 20.6**
    - `// Feature: event-ticketing-platform, Property 16: ...` — Eris, min 100 iterations (hit round-half-up boundaries)

  - [x] 9.4 Write property test for Order_Total consistency across fee modes
    - **Property 17: Order_Total consistency across fee modes** — Absorb: Order_Total = subtotal, Booking_Fee = 0; Pass_On: Booking_Fee = fee, Order_Total = subtotal + fee; direct charge amount = Order_Total
    - **Validates: Requirements 10.10, 12.1, 13.4, 13.5**
    - `// Feature: event-ticketing-platform, Property 17: ...` — Eris, min 100 iterations

  - [x] 9.5 Write property test for free orders incur no money and no charge
    - **Property 18: Free orders incur no money and no charge** — free-only orders yield zero subtotal/fee/booking/total, no Stripe charge, completes without payment, regardless of Fee_Handling_Mode
    - **Validates: Requirements 10.9, 12.9, 13.7**
    - `// Feature: event-ticketing-platform, Property 18: ...` — Eris, min 100 iterations

  - [x] 9.6 Write property test for fee-mode change does not mutate existing orders
    - **Property 19: Fee-mode change does not mutate existing orders** — changing Fee_Handling_Mode leaves existing orders' snapshot/money unchanged; later orders use the new mode
    - **Validates: Requirements 13.8**
    - `// Feature: event-ticketing-platform, Property 19: ...` — Eris, min 100 iterations

- [x] 10. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 11. Branding and ticket customisation
  - [x] 11.1 Implement BrandingController (logo, colour, T&Cs, custom fields, event overrides)
    - Store and display uploaded logo on Storefront and tickets; apply primary colour to Storefront; show T&Cs at checkout; define custom ticket info fields printed on tickets; apply Event-level overrides where set
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_
    - _Design: BrandingController_

  - [x] 11.2 Write feature tests for branding resolution and rendering
    - Assert Company vs Event-level branding/field resolution and that logo/colour/T&Cs/custom fields render on the relevant surfaces
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [x] 12. Public storefront, event pages, listing cache, and checkout order creation
  - [x] 12.1 Implement StorefrontController and EventPageController with listing cache
    - Storefront lists the Company's published events with caching applied only to public listing pages; event page shows available Ticket_Types and is not served when unpublished; updated published event details are served fresh
    - _Requirements: 8.2, 8.3, 8.4, 8.5, 9.1, 5.5_
    - _Design: StorefrontController, EventPageController_

  - [x] 12.2 Create orders/tickets/order_consents tables and CheckoutController order creation
    - Add `orders` (with `order_reference` UNIQUE across Platform, status enum, money fields, `fee_handling_mode` snapshot, `reserved_until`, Stripe/scan columns), `tickets`, and `order_consents` migrations/models
    - CheckoutController: validate customer name (1–200) and email (1–254) present/non-blank and required consents accepted (else no Order + field-specific error); create one Ticket row per purchased/claimed ticket; drive reservation (bypassing cache for availability); assign a Platform-unique Order_Reference; gate paid checkout on charges-enabled (free-only always allowed)
    - Also generate and commit the matching versioned raw SQL file(s) under `database/sql/` (e.g. `NNN_create_orders.sql`, `NNN_create_tickets.sql`, `NNN_create_order_consents.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migrations (regenerate on any change)
    - _Requirements: 8.5, 9.2, 10.1, 10.2, 10.3, 10.4, 10.5, 10.8, 10.13, 22.4_
    - _Design: CheckoutController, orders/tickets/order_consents data models, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 12.3 Write property test for checkout customer and consent validation
    - **Property 12: Checkout customer and consent validation** — create Order iff name 1–200 + email 1–254 non-blank + all required consents accepted; else no Order + field/consent error; stored consents equal submitted
    - **Validates: Requirements 10.1, 10.2, 10.3, 10.4, 22.4**
    - `// Feature: event-ticketing-platform, Property 12: ...` — Eris, min 100 iterations

  - [x] 12.4 Write property test for ticket rows match requested quantities
    - **Property 13: Ticket rows match requested quantities** — one Ticket row per purchased/claimed ticket; totals and per-type counts equal requested quantities
    - **Validates: Requirements 10.5**
    - `// Feature: event-ticketing-platform, Property 13: ...` — Eris, min 100 iterations

  - [x] 12.5 Write property test for Order_Reference uniqueness
    - **Property 14: Order_Reference uniqueness** — all assigned Order_Reference values are distinct across any Companies
    - **Validates: Requirements 10.13**
    - `// Feature: event-ticketing-platform, Property 14: ...` — Eris, min 100 iterations

  - [x] 12.6 Write property test for charges-enabled gating
    - **Property 15: Charges-enabled gating** — paid checkout permitted iff connected Stripe account with charges enabled; free-only always permitted
    - **Validates: Requirements 10.8, 11.3**
    - `// Feature: event-ticketing-platform, Property 15: ...` — Eris, min 100 iterations

  - [x] 12.7 Write feature tests for landing/storefront/event page rendering
    - Assert route rendering for storefront listing (8.2) and event page (8.3), and unpublished events not served
    - _Requirements: 8.2, 8.3, 5.5_

- [x] 13. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 14. Stripe Connect onboarding (mocked in tests)
  - [x] 14.1 Implement StripePaymentService interface + fake and StripeConnectController onboarding
    - Define `StripePaymentService` as an interface with an SDK-backed implementation and a fake/mock bound in tests; implement Connect Standard onboarding via OAuth/Account Links, store `stripe_account_id`, read capabilities, and show not-connected status when absent
    - _Requirements: 11.1, 11.2, 11.3, 11.5_
    - _Design: StripePaymentService, StripeConnectController, Stripe boundary (Testing Strategy)_

  - [x] 14.2 Write feature test for capability-update enabling paid sales
    - Assert a connected-account capability-update path setting `stripe_charges_enabled` enables paid ticket sales (Stripe mocked)
    - _Requirements: 11.4_

- [x] 15. Stripe Checkout direct charge + return pages + free-order confirmation (mocked)
  - [x] 15.1 Create Checkout Session as direct charge with application_fee_amount and return pages
    - Create a Stripe Checkout Session as a direct charge on the connected account with `application_fee_amount` = computed Application_Fee and charge amount = Order_Total; display Ticket_Subtotal, any Booking_Fee, and Order_Total before payment; redirect to hosted Checkout; implement `StripeReturnController` success/cancel pages (display-only, never authoritative); complete free-only orders immediately without a charge; release capacity + surface error on failed/cancelled payment
    - _Requirements: 10.9, 10.10, 10.11, 10.12, 12.1, 12.5, 12.9_
    - _Design: CheckoutController, StripeReturnController, StripePaymentService, Checkout and Payment Flow_

  - [x] 15.2 Write property test for direct-charge failure leaves order unpaid
    - **Property 22: Direct-charge failure leaves order unpaid** — failed direct charge leaves Order unpaid, transfers no funds, surfaces an error (Stripe mocked)
    - **Validates: Requirements 12.8**
    - `// Feature: event-ticketing-platform, Property 22: ...` — Eris, min 100 iterations

  - [x] 15.3 Reuse Property 17 assertion against the Stripe mock
    - Assert composition against the mock: charge amount == Order_Total on the connected account and `application_fee_amount` == computed fee (covered by Property 17 PBT in 9.4 asserting the direct charge amount)
    - **Validates: Requirements 12.1, 12.2 (Stripe boundary)**

- [x] 16. Stripe webhooks: signature, idempotency, and enqueue
  - [x] 16.1 Implement VerifyStripeSignature, processed_webhooks, WebhookController + WebhookProcessor + ProcessWebhookJob
    - Add the `processed_webhooks` migration/model (`stripe_event_id` UNIQUE); `WebhookController` verifies signature (reject invalid with error, no state change), dedupes on event id, and enqueues heavy work via `ProcessWebhookJob`; `WebhookProcessor` marks the Order paid exactly once on `checkout.session.completed` (no additional charge on redelivery); handle refund, dispute, and capability-update event types
    - Also generate and commit the matching versioned raw SQL file under `database/sql/` (e.g. `NNN_create_processed_webhooks.sql`) from the migration output (`php artisan migrate --pretend` / `php artisan schema:dump`); keep the SQL byte-consistent with the migration (regenerate on any change)
    - _Requirements: 12.6, 12.7, 19.1, 19.2, 19.3, 19.4, 19.5, 11.4_
    - _Design: VerifyStripeSignature, WebhookController, WebhookProcessor, ProcessWebhookJob, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 16.2 Write property test for webhook signature gating
    - **Property 20: Webhook signature gating** — accept/process iff signature valid; invalid signatures rejected with error and no state change
    - **Validates: Requirements 19.1, 19.2**
    - `// Feature: event-ticketing-platform, Property 20: ...` — Eris, min 100 iterations

  - [x] 16.3 Write property test for webhook idempotency
    - **Property 21: Webhook idempotency** — event processed at most once; `checkout.session.completed` marks paid exactly once and creates no additional charge on redelivery
    - **Validates: Requirements 12.6, 12.7, 19.3**
    - `// Feature: event-ticketing-platform, Property 21: ...` — Eris, min 100 iterations

  - [x] 16.4 Write feature test for enqueue-on-valid-webhook
    - Assert a valid webhook enqueues heavy processing on the DB queue
    - _Requirements: 19.4_

- [x] 17. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 18. QR service, order fulfilment, and queued ticket email
  - [x] 18.1 Implement QrService, MailService abstraction, OrderFulfilmentService, and SendTicketEmailJob
    - `QrService`: generate exactly one QR per Order encoding `HMAC(secret, Order_Reference)`; verify/recompute tokens (fail for tampered/foreign tokens)
    - `MailService`: `TicketMailer` interface with `SmtpTicketMailer` (cPanel SMTP) bound in the container, swappable without changing callers
    - `OrderFulfilmentService`: on confirmation generate QR, render branded ticket with custom fields, enqueue `SendTicketEmailJob`; the job sends the branded ticket email with the QR when the queue is drained
    - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.5, 14.6_
    - _Design: QrService, MailService, OrderFulfilmentService, SendTicketEmailJob_

  - [x] 18.2 Write property test for one QR per order and token validity
    - **Property 23: One QR per order and token validity** — exactly one QR encoding `HMAC(secret, Order_Reference)`; verify succeeds for that token, fails for tampered/foreign tokens
    - **Validates: Requirements 14.1, 14.2, 16.4, 16.5**
    - `// Feature: event-ticketing-platform, Property 23: ...` — Eris, min 100 iterations

  - [x] 18.3 Write feature test for enqueue-on-confirm of the ticket email
    - Assert confirming an Order enqueues the ticket email job on the DB queue
    - _Requirements: 14.3_

- [x] 19. Web scanner and check-in
  - [x] 19.1 Implement ScanController and scanner page with camera + atomic single-scan
    - Phone-browser scanner page using the device camera (JS decoder), no app install; show "camera access required" when permission denied and perform no scan; decode token (unreadable-code message on failure); recompute HMAC (invalid-code message on mismatch); load Order scoped to scanning `company_id` (reject foreign/not-found); reject voided (failure) and already-scanned (warning + prior `scanned_at`); on valid unscanned, atomic `UPDATE ... SET scanned_at, scanned_by WHERE id=? AND scanned_at IS NULL` and display the full Order breakdown
    - _Requirements: 16.1, 16.2, 16.3, 16.4, 16.5, 16.6, 16.7, 16.8, 16.9, 16.10_
    - _Design: ScanController, QrService, Scan / Check-In Flow_

  - [x] 19.2 Write property test for single atomic check-in
    - **Property 24: Single atomic check-in** — across any interleaving of concurrent scans of a valid unscanned Order, exactly one records the check-in; `scanned_at` never changes; subsequent scans report already-scanned with the original `scanned_at`
    - **Validates: Requirements 16.8, 16.9**
    - `// Feature: event-ticketing-platform, Property 24: ...` — Eris, min 100 iterations

  - [x] 19.3 Smoke test for scanner camera/permission behaviour
    - Verify the scanner page loads and camera/permission handling (manual verification in a phone browser)
    - _Requirements: 16.1, 16.2_

- [x] 20. Refunds, cancellations, and refund/dispute webhooks
  - [x] 20.1 Implement OrderController cancel/refund and webhook void handling
    - Allow Admin/Owner to cancel and refund an Order; refund a paid Order via Stripe refund on the connected account (mocked in tests); void the Order's Tickets so the QR fails at scan; refund webhook updates Order+Ticket status; dispute webhook updates Order status
    - _Requirements: 17.1, 17.2, 17.3, 17.4, 17.5_
    - _Design: OrderController, StripePaymentService, WebhookProcessor_

  - [x] 20.2 Write property test for cancel/refund voids tickets and blocks scan
    - **Property 25: Cancel/refund voids tickets and blocks scan** — after cancel/refund (incl. refund/dispute webhooks) all Tickets voided and any subsequent scan is rejected as failure
    - **Validates: Requirements 16.10, 17.3, 17.4, 17.5**
    - `// Feature: event-ticketing-platform, Property 25: ...` — Eris, min 100 iterations

  - [x] 20.3 Write feature tests for refund/dispute webhook transitions
    - Assert refund webhook (17.2, 17.4) and dispute webhook (17.5) transitions (Stripe mocked)
    - _Requirements: 17.2, 17.4, 17.5_

- [x] 21. Complimentary / free ticket issuance
  - [x] 21.1 Implement complimentary ticket issuance in OrderController
    - Allow an Admin to issue complimentary tickets: create an Order with Ticket rows without payment, generate a QR and enqueue the ticket email, and count comps against the Event overall capacity and the Ticket_Type capacity (via CapacityReservationService)
    - _Requirements: 18.1, 18.2, 18.3_
    - _Design: OrderController, CapacityReservationService_
    - Note: comp capacity accounting is covered by Property 10 (task 8.3), ticket-row counts by Property 13 (task 12.4), and zero-money by Property 18 (task 9.5)

- [x] 22. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 23. Super-admin dashboard
  - [x] 23.1 Implement super-admin guard and SuperAdmin controllers
    - Separate super-admin routing and guard; deny non-super-admins; `SuperAdmin\TransactionController` shows all Companies, all transactions, and total Application_Fees earned; `SuperAdmin\CompanyController` suspend/unsuspend; `SuperAdmin\FeeController` sets Global_Fee_Percent and per-Company Company_Fee_Percent
    - _Requirements: 20.1, 20.2, 20.3, 20.4, 20.5, 20.6, 20.7_
    - _Design: SuperAdmin controllers_

  - [x] 23.2 Write property test for total fees earned aggregation
    - **Property 26: Total fees earned aggregation** — total Application_Fees reported equals the sum of recorded Application_Fee values of paid Orders across all Companies
    - **Validates: Requirements 20.2**
    - `// Feature: event-ticketing-platform, Property 26: ...` — Eris, min 100 iterations
    - Note: super-admin access control (20.7) covered by Property 6 (task 4.4); fee-percent application (20.5, 20.6) by Property 16 (task 9.3)

- [x] 24. Accountant reporting and payouts (read-only)
  - [x] 24.1 Implement ReportController with Accountant read-only scope
    - Display reports and payout information scoped to the Accountant's own Company; deny Accountant modifications to Events/Ticket_Types/Orders with an authorisation error
    - _Requirements: 21.1, 21.2, 21.3_
    - _Design: ReportController_
    - Note: read-only enforcement (21.1, 21.2) covered by Property 6 (task 4.4); Company scoping by Property 1 (task 2.4)

- [x] 25. GDPR export/anonymise, privacy policy, and consent surfacing
  - [x] 25.1 Implement GdprController + GdprService and privacy policy page
    - Export a Customer's stored personal data; delete/anonymise personal data (name, email, custom personal fields) while retaining transactional records (references, amounts, fees), scoped per requesting Company; provide a privacy policy page; surface consent capture at checkout (stored on the Order)
    - _Requirements: 22.1, 22.2, 22.3, 22.4, 22.5_
    - _Design: GdprController, GdprService_

  - [x] 25.2 Write property test for GDPR anonymisation retains transactional records
    - **Property 27: GDPR anonymisation retains transactional records** — after scoped delete/anonymise no identifiable personal data remains while transactional records are retained; export contains every stored personal field
    - **Validates: Requirements 22.1, 22.2**
    - `// Feature: event-ticketing-platform, Property 27: ...` — Eris, min 100 iterations
    - Note: consent storage (22.4) covered by Property 12 (task 12.3); per-Company scoping (22.5) by Property 1 (task 2.4)

  - [x] 25.3 Write feature test for privacy policy page rendering
    - Assert the privacy policy page renders
    - _Requirements: 22.3_

- [x] 26. Hosting / deployment wiring (shared cPanel)
  - [x] 26.1 Configure cron scheduler, queue draining, storage symlink, webhook exemptions, and HMAC secret stability
    - Configure the scheduler to run `queue:work --stop-when-empty` and `ReleaseExpiredReservationsJob` each minute (drained by the per-minute cron `schedule:run`); ensure `jobs`/`failed_jobs` exist and failures are retryable; add `storage:link` (or manual symlink) for logo exposure; register the `POST /stripe/webhook` route exempt from CSRF and tenant-slug resolution; place Stripe keys/Connect client id/webhook signing secret/HMAC secret/SMTP creds in `.env` outside the web root and keep the HMAC secret stable across deploys
    - Assemble/verify the ordered `database/sql/` files (schema + `platform_settings` seed, including the `migrations`, `jobs`, `failed_jobs`, and `processed_webhooks` tables) form a complete schema applyable via phpMyAdmin in filename order; add a comment header per file and maintain a short `CHANGELOG` for manual tracking; document the phpMyAdmin Import / SQL application steps (paste files in filename order, no SSH/web deploy route)
    - _Requirements: 15.2, 15.3, 7.1, 19.1_
    - _Design: Hosting and Deployment Notes, Hosting and Deployment Notes → Database schema (no SSH on prod)_

  - [x] 26.2 Smoke test for cron command draining the DB queue
    - Verify `schedule:run` triggers `queue:work --stop-when-empty` and drains queued jobs
    - _Requirements: 15.2, 15.3_

  - [x] 26.3 Smoke test that generated SQL matches the migrations (drift guard)
    - Apply the concatenated ordered `database/sql/` files to a scratch MySQL database and diff the resulting schema against a database built from `php artisan migrate`; assert they match so the raw SQL never drifts from the migrations
    - _Design: Hosting and Deployment Notes → Database schema (no SSH on prod)_

- [x] 27. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional (tests and hardening) and can be skipped for a faster MVP; core implementation tasks are never marked optional.
- Each task references specific requirement sub-clauses and design components/properties for traceability.
- Checkpoints ensure incremental, demoable validation between slices.
- Property-based tests use a PHP PBT library (e.g. Eris — never hand-rolled), run a minimum of 100 iterations, use one PBT per property, and are tagged `// Feature: event-ticketing-platform, Property {number}: {text}`.
- Stripe is always mocked in automated tests; card data never appears.
- All 27 correctness properties are covered by exactly one PBT task each; every requirement (1–22) is covered by at least one implementation task.
- Money is handled only through `FeeCalculationService` in integer minor currency units.
- Laravel migrations are the single source of truth for the schema; for every schema change a matching versioned raw `.sql` file (plus a `platform_settings` seed) is generated from the migrations (`migrate --pretend` / `schema:dump`, never hand-written) and committed under `database/sql/` so prod can be stood up by pasting SQL into phpMyAdmin (no SSH). The generated SQL includes the Laravel-managed `migrations`, `jobs`, `failed_jobs`, and `processed_webhooks` tables and is kept byte-consistent with the migrations.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1"] },
    { "id": 1, "tasks": ["1.2", "1.3"] },
    { "id": 2, "tasks": ["2.1", "9.1"] },
    { "id": 3, "tasks": ["2.2", "2.3", "9.2"] },
    { "id": 4, "tasks": ["2.4", "2.5", "3.1", "4.1", "9.3", "9.4", "9.5", "9.6"] },
    { "id": 5, "tasks": ["3.2", "4.2", "4.3", "4.4", "4.6", "5.1", "6.1", "8.1", "14.1"] },
    { "id": 6, "tasks": ["4.5", "5.2", "6.2", "8.2", "11.1", "14.2"] },
    { "id": 7, "tasks": ["6.3", "6.4", "8.3", "8.4", "11.2", "12.1", "12.2"] },
    { "id": 8, "tasks": ["12.3", "12.4", "12.5", "12.6", "12.7", "15.1"] },
    { "id": 9, "tasks": ["15.2", "15.3", "16.1"] },
    { "id": 10, "tasks": ["16.2", "16.3", "16.4", "18.1"] },
    { "id": 11, "tasks": ["18.2", "18.3", "19.1", "20.1", "21.1"] },
    { "id": 12, "tasks": ["19.2", "19.3", "20.2", "20.3", "23.1", "24.1", "25.1"] },
    { "id": 13, "tasks": ["23.2", "25.2", "25.3", "26.1"] },
    { "id": 14, "tasks": ["26.2", "26.3"] }
  ]
}
```
