# Requirements Document

## Introduction

Events by CK Enterprises (the Events_App) will be linked to the existing CK CRM (the CRM) ticket and support system instead of building a separate one. New Events companies are created as CRM customers, support requests raised in Events become CRM tickets handled by CRM staff, and Events customers follow and reply to those tickets without leaving the Events site.

Communication is server-to-server over a token-secured HTTP API, with no shared database credentials. The CRM exposes a dedicated integration API under `/api/integrations/events/*`. The Events_App calls it through queued jobs so a CRM outage never breaks signup or the support form. The CRM sends status and reply updates back to the Events_App by webhook, with polling as a fallback.

## Glossary

- **Events_App**: The Events by CK Enterprises Laravel application.
- **CRM**: The CK CRM Laravel application at `ck-crm`, which owns customers, tickets and replies for staff.
- **Integration_API**: The CRM route group `/api/integrations/events/*` used only by the Events_App.
- **Integration_Credential**: A CRM-issued token carrying only Events integration abilities, distinct from admin Sanctum tokens.
- **Company**: An Events_App tenant record (`companies`).
- **CRM_Customer**: A CRM `Customer` record, keyed by `company_id`.
- **External_Ref**: A CRM customer column holding the Events Company identifier, used to match customers idempotently.
- **Support_Request**: An Events_App `SupportRequest` record, scoped to a Company.
- **CRM_Ticket**: A CRM `Ticket` record, keyed by `ticket_id`.
- **Public_Reply**: A CRM `TicketReply` where `is_internal` is false.
- **Internal_Note**: A CRM `TicketReply` where `is_internal` is true.
- **Sync_Job**: A queued Events_App job that calls the Integration_API.
- **Webhook_Receiver**: The Events_App endpoint that accepts CRM update events.
- **Poller**: The Events_App scheduled command that fetches CRM ticket updates for open Support_Requests.
- **Support_Portal**: The Events_App dashboard pages "My support requests" list and detail.
- **Backfill_Command**: An Events_App Artisan command that syncs existing Companies and Support_Requests to the CRM.
- **Idempotency_Key**: A unique key sent with a write request so repeated delivery produces one result.
- **Signature**: An HMAC of the request body computed with a shared secret and sent in a request header.
- **Customer_Reply_Author**: The identity recorded on a CRM reply made by an Events user, comprising name, email and Events user id.

## Requirements

### Requirement 1: Scoped CRM integration API

**User Story:** As a CRM administrator, I want a dedicated, scoped integration API, so that the Events_App can integrate without receiving admin access or database credentials.

#### Acceptance Criteria

1. THE CRM SHALL expose the Integration_API under the path prefix `/api/integrations/events`.
2. WHEN a request to the Integration_API lacks a valid Integration_Credential, THE CRM SHALL respond with HTTP 401 and perform no data change.
3. WHEN a request to the Integration_API uses a credential lacking the required ability, THE CRM SHALL respond with HTTP 403 and perform no data change.
4. THE CRM SHALL reject use of an existing admin Sanctum token on the Integration_API with HTTP 403.
5. WHEN a client exceeds 120 requests per minute on the Integration_API, THE CRM SHALL respond with HTTP 429.
6. WHERE an allowed-IP list is configured, THE CRM SHALL respond with HTTP 403 to Integration_API requests from addresses outside the list.
7. WHEN a write request to the Integration_API carries an Idempotency_Key already processed, THE CRM SHALL return the original response and create no duplicate record.
8. WHEN a write request carries an invalid Signature, THE CRM SHALL respond with HTTP 401 and perform no data change.

### Requirement 2: Company-to-customer sync on signup

**User Story:** As a sales or support staff member, I want new Events companies to appear in the CRM, so that I can see who raised a ticket.

#### Acceptance Criteria

1. WHEN a Company is created in the Events_App, THE Events_App SHALL dispatch a Sync_Job to upsert the matching CRM_Customer.
2. WHEN the CRM receives an upsert for an External_Ref that exists, THE CRM SHALL update that CRM_Customer and create no new record.
3. WHEN the CRM receives an upsert for an External_Ref that does not exist and an existing CRM_Customer has the same email address, THE CRM SHALL link that CRM_Customer by setting its External_Ref.
4. WHEN the CRM receives an upsert matching neither External_Ref nor email, THE CRM SHALL create a new CRM_Customer and return its `company_id`.
5. WHEN the CRM returns a `company_id`, THE Events_App SHALL store it on the Company as `crm_customer_id`.
6. THE CRM SHALL store External_Ref on CRM_Customer with a unique index.
7. IF the CRM is unreachable or returns an error during customer sync, THEN THE Events_App SHALL complete the signup and retry the Sync_Job with backoff for up to 5 attempts.
8. IF all retry attempts for a Sync_Job fail, THEN THE Events_App SHALL record the failure with the Company id and error message in the application log.

### Requirement 3: Company profile sync

**User Story:** As a support staff member, I want company profile changes in Events to reach the CRM, so that customer details stay current.

#### Acceptance Criteria

1. WHEN the name, email, phone or address of a Company changes, THE Events_App SHALL dispatch a Sync_Job to patch the CRM_Customer.
2. WHEN the Events_App patches a CRM_Customer, THE CRM SHALL update only the fields supplied in the request.
3. THE Events_App SHALL treat the sync direction as one-way from Events_App to CRM.
4. IF a Company has no `crm_customer_id` when a profile change occurs, THEN THE Events_App SHALL perform a customer upsert instead of a patch.
5. WHEN a Company changes only fields that are not synced, THE Events_App SHALL dispatch no Sync_Job.

### Requirement 4: Support request to CRM ticket

**User Story:** As an Events customer, I want my support form submission to reach CRM staff, so that I get help without using a second system.

#### Acceptance Criteria

1. WHEN a Support_Request is raised through `SupportTicketCreator::raise`, THE Events_App SHALL save the Support_Request locally and dispatch a Sync_Job to create the CRM_Ticket.
2. WHEN the CRM receives a create-ticket request, THE CRM SHALL create the CRM_Ticket through `TicketService::create` so that notifications and activity logging run.
3. THE Events_App SHALL send the Company's `crm_customer_id`, category, subject, message, access consent flag, access consent timestamp and the submitting user's name and email with each create-ticket request.
4. THE CRM SHALL map the Events categories account, events, orders, payments, billing, technical, abuse and other to CRM `request_category` values using a documented mapping table.
5. WHEN the CRM returns a `ticket_id`, THE Events_App SHALL store it on the Support_Request together with a last-synced timestamp.
6. IF the Company has no `crm_customer_id` when a ticket is synced, THEN THE Events_App SHALL upsert the CRM_Customer before creating the CRM_Ticket.
7. IF the CRM is unreachable, THEN THE Events_App SHALL still show the customer a confirmation that the request was received.
8. THE CRM SHALL record access consent and its timestamp on the CRM_Ticket.
9. WHEN the CRM_Ticket is created, THE CRM SHALL suppress the customer notification email if the Events_App requests `notify_customer` as false.

### Requirement 5: My support requests list

**User Story:** As an Events customer, I want a list of my support requests with their status, so that I can see progress.

#### Acceptance Criteria

1. THE Support_Portal SHALL list the Support_Requests of the authenticated user's Company, with subject, category, status and last-updated time.
2. WHEN a user opens the Support_Portal, THE Events_App SHALL return only Support_Requests belonging to the user's Company.
3. IF a user requests the detail page of a Support_Request from another Company, THEN THE Events_App SHALL respond with HTTP 404.
4. THE Events_App SHALL map CRM statuses to the Events statuses open, in_progress, resolved and closed using a documented mapping table.
5. WHILE a Support_Request has no CRM_Ticket mapping, THE Support_Portal SHALL display it with status open and a "sending" indicator.
6. THE Support_Portal SHALL paginate the list at 20 items per page.

### Requirement 6: Staff replies visible to customers

**User Story:** As an Events customer, I want to read staff replies in Events, so that I never need to visit the CRM.

#### Acceptance Criteria

1. THE Support_Portal detail page SHALL display the original message, the current status and all Public_Replies in chronological order.
2. THE CRM SHALL exclude Internal_Notes from every Integration_API response.
3. THE Events_App SHALL store only Public_Replies and never store Internal_Notes.
4. THE Support_Portal SHALL show for each reply the author type (staff or customer), the display name and the timestamp.
5. THE CRM SHALL expose a staff member's first name or a team label as the staff display name and omit staff email addresses.

### Requirement 7: Customer replies from Events

**User Story:** As an Events customer, I want to reply to a support request from the Events site, so that I can add information.

#### Acceptance Criteria

1. WHEN a user submits a reply on a Support_Request detail page, THE Events_App SHALL validate that the message is present and not longer than 5000 characters.
2. WHEN a valid reply is submitted, THE Events_App SHALL store it locally and dispatch a Sync_Job to post it to the CRM.
3. WHEN the CRM receives a customer reply, THE CRM SHALL create a Public_Reply on the CRM_Ticket attributed to the Customer_Reply_Author.
4. WHEN a customer reply is added to a CRM_Ticket with status resolved or closed, THE CRM SHALL reopen the CRM_Ticket.
5. IF a Support_Request has status closed, THEN THE Events_App SHALL reject new replies and display a message that the request is closed.
6. IF the CRM rejects a reply with a validation error, THEN THE Events_App SHALL mark the local reply as failed and show the failure on the detail page.
7. WHEN the CRM receives a customer reply, THE CRM SHALL notify the assigned staff member through the existing CRM notification mechanism.

### Requirement 8: Webhook and polling updates

**User Story:** As an Events customer, I want status changes and staff replies to appear in Events promptly, so that the thread stays current.

#### Acceptance Criteria

1. WHEN a CRM_Ticket linked to an Events customer changes status, THE CRM SHALL send a signed webhook to the Webhook_Receiver.
2. WHEN a Public_Reply is added by staff to a linked CRM_Ticket, THE CRM SHALL send a signed webhook to the Webhook_Receiver.
3. WHEN the Webhook_Receiver receives a request with a valid Signature, THE Events_App SHALL update the matching Support_Request status and store new Public_Replies without duplicating existing ones.
4. IF the Webhook_Receiver receives a request with an invalid or missing Signature, THEN THE Events_App SHALL respond with HTTP 401 and change no data.
5. IF a webhook references a `ticket_id` unknown to the Events_App, THEN THE Events_App SHALL respond with HTTP 202 and change no data.
6. IF a webhook delivery fails, THEN THE CRM SHALL retry delivery with backoff for up to 5 attempts.
7. WHEN the Poller runs, THE Events_App SHALL fetch updates for Support_Requests with status open or in_progress and apply them with the same rules as webhook updates.
8. THE Events_App SHALL run the Poller every 10 minutes.
9. WHEN a customer opens a Support_Request detail page whose last-synced timestamp is older than 5 minutes and status is open or in_progress, THE Events_App SHALL refresh that Support_Request from the CRM.
10. WHEN a staff reply is applied to a Support_Request, THE Events_App SHALL notify the submitting user by email with a link to the Support_Portal detail page.

### Requirement 9: Backfill of existing data

**User Story:** As a platform operator, I want existing companies and support requests synced, so that history is available in the CRM.

#### Acceptance Criteria

1. THE Events_App SHALL provide a Backfill_Command that upserts every Company lacking a `crm_customer_id`.
2. THE Backfill_Command SHALL create CRM_Tickets for Support_Requests lacking a ticket mapping.
3. WHEN the Backfill_Command runs more than once, THE Events_App SHALL create no duplicate CRM_Customers or CRM_Tickets.
4. WHERE the `--dry-run` option is supplied, THE Backfill_Command SHALL report the records it would sync and send no write requests.
5. WHEN the Backfill_Command finishes, THE Backfill_Command SHALL print counts of synced, skipped and failed records.
6. WHEN the Backfill_Command creates CRM_Tickets for historical Support_Requests, THE Backfill_Command SHALL request that the CRM send no customer notification emails.

### Requirement 10: Configuration and safety

**User Story:** As a developer, I want the integration configured through environment settings, so that it deploys safely across environments.

#### Acceptance Criteria

1. THE Events_App SHALL read the CRM base URL, Integration_Credential, webhook secret, request timeout and enabled flag from `config/services.php` backed by `.env` values.
2. THE Events_App SHALL document each new setting in `.env.example` without real secrets.
3. WHILE the enabled flag is false, THE Events_App SHALL skip all CRM calls and keep support requests stored locally.
4. THE Events_App SHALL apply a timeout of no more than 10 seconds to each CRM request.
5. THE Events_App SHALL send the Integration_Credential and an Idempotency_Key with every write request.
6. THE Events_App SHALL exclude the Integration_Credential and webhook secret from logs and error messages.
7. WHEN the CRM base URL does not use HTTPS and the Events_App environment is production, THE Events_App SHALL refuse to send CRM requests and log a configuration error.

### Requirement 11: Automated test coverage

**User Story:** As a developer, I want the integration covered by automated tests, so that regressions are caught without a live CRM.

#### Acceptance Criteria

1. THE Events_App test suite SHALL simulate the CRM using `Http::fake()` and make no real network calls.
2. THE Events_App test suite SHALL verify the customer sync, ticket creation, reply posting, webhook handling, polling and Backfill_Command behaviours in Requirements 2 to 9.
3. THE Events_App test suite SHALL verify that a user cannot view or reply to another Company's Support_Request.
4. THE CRM test suite SHALL verify authentication, ability checks, idempotency, signature validation and exclusion of Internal_Notes for the Integration_API.
5. THE CRM test suite SHALL verify that customer upsert matches by External_Ref first, then by email, and otherwise creates a record.
