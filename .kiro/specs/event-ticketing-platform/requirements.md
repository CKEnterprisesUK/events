# Requirements Document

## Introduction

The Event Ticketing Platform is a multi-tenant SaaS product, branded as a CK Enterprises UK offering, that enables charities and small event organisers to sell tickets online. The platform is built on Laravel (PHP) with MySQL and is deployed on shared cPanel hosting at the domain `events.domain`. Core design goals are simplicity of event setup, checkout, and database design.

The system uses a single shared database with tenant isolation scoped by `company_id`, path-based routing for public storefronts and events, Stripe Connect (Standard) for payments so each client collects funds into their own Stripe account, and a database-driven background queue drained by cron (no persistent worker). Customers receive a single QR code per order by email, and check-in is performed via a phone-browser web scanner. CK Enterprises operates a separate super-admin dashboard to oversee all clients, transactions, platform fees, suspensions, and fee configuration.

Everything described in this document is in scope for the MVP.

## Glossary

- **Platform**: The overall Event Ticketing Platform software and its shared infrastructure operated by CK Enterprises.
- **CK_Enterprises**: The company operating the Platform; holder of the super-admin role.
- **Super_Admin**: A CK Enterprises operator with Platform-wide administrative access via the super-admin dashboard.
- **Company**: A client organisation (charity or event organiser) that is a tenant on the Platform. Identified internally by `company_id` and publicly by a unique `company-slug`.
- **Company_Slug**: The unique, URL-safe identifier for a Company used in path-based routing (e.g. `events.domain/{company-slug}/`).
- **Owner**: The single user per Company responsible for billing, Stripe connection, settings, and user management; also the compliance contact.
- **Admin**: A Company user who manages events, ticket types, and orders (including cancellations, refunds, and issuing free/comp tickets).
- **Accountant**: A Company user with read-only access to reports and payouts.
- **Scanner_User**: A Company user restricted to check-in (scanning) functions only.
- **Company_User**: Any user belonging to a Company, holding one of the roles Owner, Admin, Accountant, or Scanner.
- **Customer**: An end user who purchases or claims tickets from a Company storefront. Not an authenticated Platform account.
- **Storefront**: A Company's public ticket-selling site at `events.domain/{company-slug}/`.
- **Landing_Page**: The Platform's public root page at `events.domain`.
- **Event**: A scheduled occurrence for which a Company sells tickets, addressed at `events.domain/{company-slug}/{event-id}/`.
- **Ticket_Type**: A category of ticket within an Event, with a name, price, capacity, and sale window.
- **Order**: A Customer purchase or claim transaction against an Event, capturing customer details, consent, and one or more Ticket rows.
- **Ticket**: An individual ticket row belonging to an Order, used to display the Order breakdown.
- **Order_Reference**: The unique reference identifying an Order.
- **QR_Token**: A signed opaque token encoded in an Order's QR code, computed as an HMAC of the Order_Reference.
- **QR_Code**: The single scannable code issued per Order, encoding the QR_Token.
- **Scanner**: The phone-browser, camera-based web scanner used for check-in.
- **Stripe_Connect**: Stripe Connect Standard, by which a Company connects its own Stripe account to receive payments.
- **Stripe_Checkout**: Stripe's hosted, redirect-based payment page used to collect payment.
- **Application_Fee**: The Platform's per-transaction fee, applied as `application_fee_amount` on a direct charge.
- **Global_Fee_Percent**: The default Platform fee percentage set by the Super_Admin, applied to Companies without an override.
- **Company_Fee_Percent**: A per-Company Platform fee percentage that overrides the Global_Fee_Percent for that Company.
- **Fee_Handling_Mode**: A per-Company setting determining who bears the Application_Fee. One of Absorb or Pass_On.
- **Absorb**: Fee_Handling_Mode where the Company bears the Application_Fee; the Customer pays the Ticket_Type face value and the Application_Fee is deducted from the Company's proceeds.
- **Pass_On**: Fee_Handling_Mode where the Customer bears the Application_Fee; a Booking_Fee equal to the Application_Fee is added to the Order total at checkout.
- **Booking_Fee**: The amount added to an Order total in Pass_On mode, shown to the Customer as a distinct line item, equal to the Application_Fee.
- **Ticket_Subtotal**: The sum of the face values of all Ticket_Types in an Order, before any Booking_Fee.
- **Order_Total**: The amount the Customer pays for an Order. In Absorb mode it equals the Ticket_Subtotal; in Pass_On mode it equals the Ticket_Subtotal plus the Booking_Fee.
- **Tenant_Resolution_Middleware**: The middleware that resolves the active Company from the Company_Slug and applies the global `company_id` query scope.
- **DB_Queue**: The database queue driver used for background jobs.
- **Cron_Runner**: The scheduled cron process that runs `schedule:run` and drains the DB_Queue via `queue:work --stop-when-empty` each minute.
- **Webhook_Endpoint**: The Platform endpoint that receives and processes Stripe webhook events.
- **Suspended_Company**: A Company whose access has been disabled by the Super_Admin.

## Requirements

### Requirement 1: Tenant Resolution and Data Isolation

**User Story:** As CK Enterprises, I want each Company's data isolated within a shared database, so that Companies cannot see or affect one another's data.

#### Acceptance Criteria

1. THE Platform SHALL store all Company-owned records with a `company_id` attribute identifying the owning Company.
2. WHEN a request is made to a path beginning with `/{company-slug}/`, THE Tenant_Resolution_Middleware SHALL resolve the active Company by matching the requested Company_Slug against stored Company_Slug values using a case-insensitive comparison within 200 milliseconds.
3. IF no Company matches the requested Company_Slug, THEN THE Platform SHALL deny the request, return an HTTP 404 response, and establish no active Company for the request.
4. WHILE a Company is resolved for a request, THE Platform SHALL apply a global query scope that restricts all read, write, update, and delete operations to records whose `company_id` equals the resolved Company's `company_id`.
5. WHEN a Company_User requests a record whose `company_id` differs from the resolved Company's `company_id`, THE Platform SHALL deny access, return an HTTP 404 response, and make no modification to the requested record.
6. THE Company_Slug SHALL be unique across all Companies, contain between 1 and 255 characters, and match a pattern of lowercase alphanumeric characters and hyphens only.
7. IF a request path does not begin with a `/{company-slug}/` segment, THEN THE Tenant_Resolution_Middleware SHALL establish no active Company and THE Platform SHALL deny access to all Company-owned records.

### Requirement 2: Company Suspension Enforcement

**User Story:** As a Super_Admin, I want to suspend a Company, so that a non-compliant or non-paying client can no longer operate on the Platform.

#### Acceptance Criteria

1. WHEN a Customer requests the Storefront of a Suspended_Company, THE Platform SHALL return an HTTP 404 response.
2. WHEN a Customer requests an Event page of a Suspended_Company, THE Platform SHALL return an HTTP 404 response.
3. WHEN a Company_User of a Suspended_Company attempts to log in, THE Platform SHALL block the login and deny access.
4. WHILE a Company is suspended, THE Platform SHALL prevent that Company from selling tickets.
5. WHEN a Super_Admin unsuspends a Company, THE Platform SHALL restore Storefront access and Company_User login for that Company.

### Requirement 3: Authentication and Role-Based Authorisation

**User Story:** As a Company_User, I want role-based access, so that each user can only perform actions appropriate to their role.

#### Acceptance Criteria

1. THE Platform SHALL support exactly four Company roles: Owner, Admin, Accountant, and Scanner.
2. THE Platform SHALL require exactly one Owner per Company, and IF an action would result in a Company having zero Owners or more than one Owner, THEN THE Platform SHALL reject the action and return an error indicating the single-Owner constraint, leaving the existing Owner assignment unchanged.
3. WHERE a Company_User holds the Owner role, THE Platform SHALL grant access to billing, Stripe connection, Company settings, and user management functions.
4. WHERE a Company_User holds the Admin role, THE Platform SHALL grant access to manage Events, Ticket_Types, and Orders, including cancellations, refunds, and issuing free or complimentary tickets.
5. WHERE a Company_User holds the Accountant role, THE Platform SHALL grant read-only access to reports and payouts.
6. WHERE a Company_User holds the Scanner role, THE Platform SHALL grant access to check-in functions only.
7. IF a Company_User attempts an action not permitted by that user's role, THEN THE Platform SHALL deny the action, return an authorisation error indicating the action is not permitted for the user's role, and leave all data unchanged.
8. WHEN a Company_User authenticates successfully, THE Platform SHALL scope that user's session to the user's own Company only.
9. IF a Company_User submits authentication credentials that do not match a valid Company_User account, THEN THE Platform SHALL deny access, return an error indicating the credentials are invalid, and grant no session.
10. IF a Company_User attempts to access or act on data belonging to a Company other than the user's own Company, THEN THE Platform SHALL deny the action and return an authorisation error indicating cross-Company access is not permitted.
11. WHILE a Company_User session has been inactive for 30 minutes or longer, THE Platform SHALL invalidate the session and require re-authentication before permitting any further action.

### Requirement 4: User Invitation and Management

**User Story:** As an Owner, I want to invite users and assign roles, so that I can build my team on the Platform.

#### Acceptance Criteria

1. WHEN an Owner invites a user by email address, THE Platform SHALL create an invitation associated with the Owner's Company and the assigned role.
2. WHEN an invited user accepts an invitation, THE Platform SHALL create a Company_User with the assigned role scoped to the inviting Company.
3. WHEN an Owner changes a Company_User's role, THE Platform SHALL update that user's permissions to match the newly assigned role.
4. WHEN an Owner removes a Company_User, THE Platform SHALL revoke that user's access to the Company.
5. IF an Owner attempts to remove or demote the last remaining Owner of a Company, THEN THE Platform SHALL reject the action and return an error indicating that a Company must retain one Owner.
6. WHERE a user is invited, THE assigned role SHALL be one of Admin, Accountant, or Scanner.

### Requirement 5: Event Management

**User Story:** As an Admin, I want to create and manage events, so that I can sell tickets for my Company's events.

#### Acceptance Criteria

1. WHEN an Admin creates an Event with the required Event details, THE Platform SHALL persist the Event scoped to the Admin's Company.
2. THE Platform SHALL allow an Admin to set an overall capacity for an Event.
3. WHEN an Admin updates an Event, THE Platform SHALL persist the updated Event details.
4. WHEN an Admin publishes an Event, THE Platform SHALL make the Event page available at `events.domain/{company-slug}/{event-id}/`.
5. WHILE an Event is unpublished, THE Platform SHALL prevent Customers from viewing or purchasing tickets for that Event.
6. WHEN the sum of tickets sold for an Event reaches the Event's overall capacity, THE Platform SHALL prevent further ticket sales for that Event.

### Requirement 6: Ticket Type Management

**User Story:** As an Admin, I want multiple ticket types per event with prices, quantities, and sale windows, so that I can offer different tiers of access.

#### Acceptance Criteria

1. WHEN an Admin creates a Ticket_Type with a name of 1 to 100 characters, a price from 0.00 to 999,999.99, a capacity from 1 to 1,000,000, a sale window start, and a sale window end, THE Platform SHALL record the Ticket_Type name, price, capacity, sale window start, and sale window end.
2. THE Platform SHALL allow an Admin to create between 1 and 50 Ticket_Types for a single Event.
3. WHERE a Ticket_Type price is set to zero, THE Platform SHALL treat the Ticket_Type as a free ticket.
4. WHILE the current time is before a Ticket_Type sale window start, THE Platform SHALL prevent Customers from purchasing that Ticket_Type and return an error indicating the sale has not started.
5. WHILE the current time is at or after a Ticket_Type sale window end, THE Platform SHALL prevent Customers from purchasing that Ticket_Type and return an error indicating the sale has ended.
6. WHEN the quantity sold for a Ticket_Type reaches that Ticket_Type's capacity, THE Platform SHALL prevent further sales of that Ticket_Type.
7. IF a Customer requests a quantity of a Ticket_Type that exceeds the Ticket_Type's remaining capacity, THEN THE Platform SHALL reject the entire request, retain the previously sold quantity unchanged, and return an error indicating insufficient availability.
8. WHILE multiple Customers concurrently purchase the same Ticket_Type, THE Platform SHALL serialize the purchase requests so that the cumulative quantity sold never exceeds the Ticket_Type's capacity, and SHALL reject each request whose requested quantity exceeds the remaining capacity at the time it is processed.
9. IF an Admin sets a Ticket_Type sale window end that is not strictly after its sale window start, THEN THE Platform SHALL reject the Ticket_Type creation and return an error indicating the sale window is invalid.

### Requirement 7: Branding and Ticket Customisation

**User Story:** As an Owner or Admin, I want to customise branding and ticket information, so that the Storefront and tickets reflect my Company's identity.

#### Acceptance Criteria

1. WHEN a Company uploads a logo, THE Platform SHALL store the logo and display it on the Company's Storefront and on issued tickets.
2. WHEN a Company sets a primary brand colour, THE Platform SHALL apply the primary brand colour to the Company's Storefront.
3. WHEN a Company sets Terms and Conditions text, THE Platform SHALL display the Terms and Conditions at checkout.
4. WHEN a Company defines customisable ticket information fields, THE Platform SHALL print the defined ticket information fields on issued tickets.
5. WHERE branding or ticket information fields are set at the Event level, THE Platform SHALL apply the Event-level values to that Event's Storefront pages and tickets.

### Requirement 8: Public Landing Page and Storefront

**User Story:** As a Customer, I want to browse a Company's events, so that I can find and select tickets to purchase.

#### Acceptance Criteria

1. WHEN a Customer requests `events.domain`, THE Platform SHALL display the Landing_Page.
2. WHEN a Customer requests `events.domain/{company-slug}/`, THE Platform SHALL display the resolved Company's Storefront listing that Company's published Events.
3. WHEN a Customer requests `events.domain/{company-slug}/{event-id}/`, THE Platform SHALL display the specified Event with its available Ticket_Types.
4. THE Platform SHALL apply caching to public Storefront listing pages.
5. WHEN a published Event's details change, THE Platform SHALL serve the updated Event details to Customers.

### Requirement 9: Storefront Cache Behaviour

**User Story:** As CK Enterprises, I want caching applied only to public listing pages, so that performance improves without serving stale checkout or availability data.

#### Acceptance Criteria

1. THE Platform SHALL apply caching only to public Storefront listing pages.
2. WHEN a Customer initiates checkout, THE Platform SHALL evaluate current Ticket_Type availability without using cached listing data.

### Requirement 10: Checkout and Order Creation

**User Story:** As a Customer, I want to select tickets and check out, so that I can complete my purchase and receive my tickets.

#### Acceptance Criteria

1. WHEN a Customer submits a checkout for 1 to 50 Ticket_Types, THE Platform SHALL create an Order recording the Customer name (1 to 200 characters) and email (1 to 254 characters).
2. IF a Customer submits a checkout with a missing or empty required name or email, THEN THE Platform SHALL reject the checkout, SHALL NOT create an Order, and SHALL return an error indicating which required field is invalid.
3. WHEN a Customer submits a checkout, THE Platform SHALL capture the consent flags on the Order.
4. IF a required consent flag is not accepted at checkout, THEN THE Platform SHALL reject the checkout, SHALL NOT create an Order, and SHALL return an error indicating the required consent was not given.
5. WHEN an Order is created, THE Platform SHALL create one Ticket row per purchased or claimed ticket, recording the associated Ticket_Type.
6. WHEN a Customer submits a checkout, THE Platform SHALL reserve the requested capacity for each Ticket_Type for a reservation window of 900 seconds, and IF the requested quantity for any Ticket_Type exceeds its remaining available capacity, THEN THE Platform SHALL reject the checkout and return an error indicating insufficient capacity.
7. IF an Order's payment is not completed within the 900-second reservation window, THEN THE Platform SHALL expire the Order and SHALL release the reserved capacity back to each associated Ticket_Type.
8. IF a Company has not connected a Stripe account with charges enabled, THEN THE Platform SHALL prevent checkout for that Company's paid Ticket_Types.
9. WHERE an Order contains only free Ticket_Types, THE Platform SHALL complete the Order without initiating payment.
10. WHEN a Customer reaches the payment step for an Order containing paid Ticket_Types, THE Platform SHALL compute the Order_Total from the Ticket_Subtotal and the Company's Fee_Handling_Mode and SHALL display the Ticket_Subtotal, any Booking_Fee, and the Order_Total to the Customer before payment.
11. WHEN a Customer proceeds to pay for an Order containing paid Ticket_Types, THE Platform SHALL redirect the Customer to Stripe_Checkout for the Order_Total.
12. IF payment for an Order fails or is cancelled at Stripe_Checkout, THEN THE Platform SHALL leave the Order incomplete, SHALL release the reserved capacity, and SHALL return an error indicating payment was not completed.
13. WHEN an Order is created, THE Platform SHALL assign an Order_Reference that is unique across all Orders on the Platform.

### Requirement 11: Stripe Connect Onboarding

**User Story:** As an Owner, I want to connect my own Stripe account, so that ticket revenue is paid directly to my Company.

#### Acceptance Criteria

1. WHEN an Owner initiates Stripe connection, THE Platform SHALL direct the Owner through the Stripe_Connect Standard onboarding flow using OAuth or Account Links.
2. WHEN Stripe_Connect onboarding completes, THE Platform SHALL store the connected Stripe account association for the Company.
3. IF a Company's connected Stripe account does not have charges enabled, THEN THE Platform SHALL prevent that Company from selling paid tickets.
4. WHEN a connected-account capability update webhook indicates charges are enabled, THE Platform SHALL enable paid ticket sales for that Company.
5. WHILE a Company has no connected Stripe account, THE Platform SHALL display the Company's connection status as not connected.

### Requirement 12: Payment Processing and Platform Fee

**User Story:** As CK Enterprises, I want to collect a per-transaction fee on each paid order, so that the Platform earns revenue while clients receive their funds.

#### Acceptance Criteria

1. WHEN a Customer pays for an Order, THE Platform SHALL create a direct charge on the paying Company's connected Stripe account for the Order_Total.
2. WHEN creating a direct charge, THE Platform SHALL set `application_fee_amount` to the computed Application_Fee for the Order, expressed in the same currency as the Order_Total and rounded to the nearest minor currency unit using round-half-up.
3. WHERE a Company has a Company_Fee_Percent override, THE Platform SHALL compute the Application_Fee as the Ticket_Subtotal multiplied by the Company_Fee_Percent, constrained to a minimum of 0 and a maximum equal to the Ticket_Subtotal.
4. WHERE a Company has no Company_Fee_Percent override, THE Platform SHALL compute the Application_Fee as the Ticket_Subtotal multiplied by the Global_Fee_Percent, constrained to a minimum of 0 and a maximum equal to the Ticket_Subtotal.
5. THE Platform SHALL NOT store Customer card data on Platform servers.
6. WHEN a `checkout.session.completed` webhook is received for an Order that is not already marked paid, THE Platform SHALL mark the Order as paid.
7. IF a `checkout.session.completed` webhook is received for an Order that is already marked paid, THEN THE Platform SHALL leave the Order status unchanged and create no additional charge.
8. IF the direct charge fails, THEN THE Platform SHALL leave the Order unpaid, transfer no funds to the Company, and return an error indication to the Customer identifying that the payment did not complete.
9. WHERE an Order consists solely of free Ticket_Types, THE Platform SHALL complete the Order without creating a Stripe charge.

### Requirement 13: Fee Handling Mode (Absorb or Pass On)

**User Story:** As an Owner, I want to choose whether my Company absorbs the Platform fee or passes it on to Customers, so that I can control whether the fee is included in my ticket price or added at checkout.

#### Acceptance Criteria

1. THE Platform SHALL provide a per-Company Fee_Handling_Mode setting with the value Absorb or Pass_On.
2. WHERE a Company has not set a Fee_Handling_Mode, THE Platform SHALL default the Fee_Handling_Mode to Absorb.
3. WHERE a Company_User holds the Owner role, THE Platform SHALL allow that user to set the Company's Fee_Handling_Mode.
4. WHERE a Company's Fee_Handling_Mode is Absorb, THE Platform SHALL set the Order_Total equal to the Ticket_Subtotal and SHALL NOT add a Booking_Fee.
5. WHERE a Company's Fee_Handling_Mode is Pass_On, THE Platform SHALL add a Booking_Fee equal to the Application_Fee to the Order_Total and SHALL set the Order_Total equal to the Ticket_Subtotal plus the Booking_Fee.
6. WHERE a Company's Fee_Handling_Mode is Pass_On, THE Platform SHALL display the Booking_Fee to the Customer as a distinct line item before payment is initiated.
7. WHERE an Order consists solely of free Ticket_Types, THE Platform SHALL add no Booking_Fee regardless of the Company's Fee_Handling_Mode.
8. WHEN the Company's Fee_Handling_Mode changes, THE Platform SHALL apply the new Fee_Handling_Mode only to Orders created after the change and SHALL leave existing Orders unchanged.

### Requirement 14: Order Fulfilment via Email and QR Code

**User Story:** As a Customer, I want to receive my ticket with a QR code by email, so that I can present it for entry.

#### Acceptance Criteria

1. WHEN an Order is confirmed, THE Platform SHALL generate exactly one QR_Code for the Order.
2. THE Platform SHALL encode a QR_Token in the QR_Code, where the QR_Token is an HMAC of the Order_Reference.
3. WHEN an Order is confirmed, THE Platform SHALL enqueue a ticket email job on the DB_Queue.
4. WHEN the Cron_Runner drains the DB_Queue, THE Platform SHALL send the ticket email containing the QR_Code to the Customer email address via cPanel SMTP.
5. THE Platform SHALL send ticket emails through a mail abstraction that supports substituting an API-based transactional mail service without changing calling code.
6. THE ticket email SHALL display the Company or Event branding and the customisable ticket information fields.

### Requirement 15: Background Job Processing via Cron Queue

**User Story:** As CK Enterprises, I want background work processed by cron on shared hosting, so that the Platform operates without a persistent queue worker.

#### Acceptance Criteria

1. THE Platform SHALL enqueue email and webhook processing jobs on the DB_Queue.
2. THE Cron_Runner SHALL run `schedule:run` each minute.
3. WHEN the Cron_Runner runs, THE Platform SHALL drain the DB_Queue using `queue:work --stop-when-empty`.
4. IF a queued job fails, THEN THE Platform SHALL record the failure for later inspection and retry.

### Requirement 16: Web-Based QR Scanning and Check-In

**User Story:** As a Scanner_User, I want to scan tickets with my phone camera in a browser, so that I can check in attendees without installing an app.

#### Acceptance Criteria

1. THE Scanner SHALL operate in a phone browser using the device camera without requiring an app installation.
2. IF the Scanner cannot obtain camera permission, THEN THE Scanner SHALL display a message indicating camera access is required and SHALL NOT perform a scan.
3. IF a scanned QR_Code cannot be decoded into a QR_Token, THEN THE Scanner SHALL reject the scan and display an unreadable-code message.
4. WHEN a QR_Code is scanned, THE Scanner SHALL validate the QR_Token by recomputing the HMAC of the Order_Reference.
5. IF the QR_Token is invalid, THEN THE Scanner SHALL reject the scan and display an invalid-code message.
6. IF a scanned Order belongs to a Company other than the scanning Company, THEN THE Scanner SHALL reject the scan and display a rejection message.
7. WHEN a valid, unscanned Order is scanned, THE Scanner SHALL display the full Order breakdown of Ticket_Types and quantities.
8. WHEN a valid, unscanned Order is scanned, THE Platform SHALL atomically mark the Order as scanned and record `scanned_at` and `scanned_by` so that only one check-in is recorded for the Order.
9. IF an Order that has already been scanned is scanned again, THEN THE Scanner SHALL display an already-scanned warning together with the previously recorded `scanned_at` value.
10. IF a voided Order is scanned, THEN THE Scanner SHALL reject the scan and display a failure message.

### Requirement 17: Refunds and Cancellations

**User Story:** As an Admin or Owner, I want to cancel and refund orders, so that I can handle customer requests and invalidate the associated tickets.

#### Acceptance Criteria

1. WHERE a Company_User holds the Admin or Owner role, THE Platform SHALL allow that user to cancel and refund an Order.
2. WHEN an Admin or Owner refunds a paid Order, THE Platform SHALL issue a Stripe refund on the Company's connected account.
3. WHEN an Order is cancelled or refunded, THE Platform SHALL void the Order's Tickets so that the Order's QR_Code fails at scan.
4. WHEN a refund webhook is received, THE Platform SHALL update the corresponding Order and Ticket status to reflect the refund.
5. WHEN a dispute webhook is received, THE Platform SHALL update the corresponding Order status to reflect the dispute.

### Requirement 18: Complimentary and Free Ticket Issuance

**User Story:** As an Admin, I want to issue free or complimentary tickets, so that I can provide access without payment.

#### Acceptance Criteria

1. WHEN an Admin issues a complimentary ticket for an Event, THE Platform SHALL create an Order with the associated Ticket rows without initiating payment.
2. WHEN a complimentary Order is created, THE Platform SHALL generate a QR_Code and enqueue a ticket email for the Order.
3. THE Platform SHALL count issued complimentary tickets against the Event's overall capacity and the relevant Ticket_Type capacity.

### Requirement 19: Stripe Webhook Handling

**User Story:** As CK Enterprises, I want Stripe webhooks verified and processed reliably, so that order and account states stay accurate.

#### Acceptance Criteria

1. WHEN a webhook request is received at the Webhook_Endpoint, THE Platform SHALL verify the Stripe webhook signature.
2. IF a webhook signature is invalid, THEN THE Platform SHALL reject the webhook and return an error response.
3. WHEN a webhook is received more than once for the same event, THE Platform SHALL process the event at most once.
4. WHEN a valid webhook is received, THE Platform SHALL enqueue heavy processing work on the DB_Queue.
5. THE Platform SHALL handle `checkout.session.completed`, refund, dispute, and connected-account capability update webhook events.

### Requirement 20: Super-Admin Dashboard

**User Story:** As a Super_Admin, I want a dashboard to oversee all clients and revenue, so that I can operate and monitor the Platform.

#### Acceptance Criteria

1. THE Platform SHALL provide a super-admin dashboard separate from the Company dashboards.
2. WHEN a Super_Admin views the dashboard, THE Platform SHALL display all Companies, all transactions, and the total Application_Fees earned.
3. WHEN a Super_Admin suspends a Company, THE Platform SHALL mark the Company as a Suspended_Company.
4. WHEN a Super_Admin unsuspends a Company, THE Platform SHALL remove the suspended status from the Company.
5. WHEN a Super_Admin sets the Global_Fee_Percent, THE Platform SHALL apply the Global_Fee_Percent to Companies without a Company_Fee_Percent override.
6. WHEN a Super_Admin sets a Company_Fee_Percent for a Company, THE Platform SHALL apply the Company_Fee_Percent to that Company's transactions.
7. WHERE a user is not a Super_Admin, THE Platform SHALL deny access to the super-admin dashboard.

### Requirement 21: Reporting and Payouts (Accountant)

**User Story:** As an Accountant, I want read-only reports and payout information, so that I can reconcile my Company's finances.

#### Acceptance Criteria

1. WHERE a Company_User holds the Accountant role, THE Platform SHALL display reports and payout information for the user's Company.
2. WHEN an Accountant attempts to modify an Event, Ticket_Type, or Order, THE Platform SHALL deny the action and return an authorisation error.
3. THE Platform SHALL scope all Accountant reports and payout information to the Accountant's own Company.

### Requirement 22: GDPR and Compliance

**User Story:** As a Customer, I want control over my personal data and clear consent, so that my privacy rights are respected.

#### Acceptance Criteria

1. WHEN a Company_User with appropriate permission requests an export of a Customer's data, THE Platform SHALL produce an export of the Customer's stored personal data.
2. WHEN a Company_User with appropriate permission requests deletion of a Customer's data, THE Platform SHALL anonymise the Customer's personal data while retaining transactional records required for reconciliation.
3. THE Platform SHALL provide a privacy policy page.
4. WHEN a Customer completes checkout, THE Platform SHALL store the Customer's consent selections on the Order.
5. THE Platform SHALL scope Customer data export and deletion operations to the requesting Company.
