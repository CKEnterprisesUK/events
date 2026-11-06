# Implementation Plan: CRM Support Integration

## Overview

Implementation is in PHP (Laravel) in two repositories. Part A builds the CRM side first (`/Applications/XAMPP/xamppfiles/htdocs/CRM_CK/ck-crm`): schema, auth middleware, the Integration_API and outbound webhooks. Part B builds the Events_App client (`/Applications/XAMPP/xamppfiles/htdocs/events`): config, `CrmClient`, sync jobs, Support_Portal, webhook receiver, Poller and Backfill_Command.

Notes on conventions:
- Events_App property tests use Eris under `tests/PBT`. The CRM has no Eris dependency, so CRM "property" tests use PHPUnit data providers with generated cases unless the user approves adding Eris there.
- All Events_App tests use `Http::fake()` and make no real network calls.
- Use `php artisan make:` commands and run `vendor/bin/pint --dirty --format agent` in each repo after changing PHP files.

## Tasks

### Part A: CRM app (`ck-crm`)

- [ ] 1. CRM schema, config and category mapping
  - [ ] 1.1 Add CRM migrations and model updates
    - `customers`: add unique nullable `external_ref` (string 100) and indexed nullable `email`; add both to `Customer::$fillable`
    - `tickets`: add `source`, `external_user_id`, `requester_name`, `requester_email`, `access_consent`, `access_consent_at`
    - `ticket_replies`: add `author_name`, `author_email`, `external_user_id`; allow `events` as a `source` value
    - Create `integration_idempotency_keys` table (scope, key, request_hash, response_status, response_body, created_at) with unique index on (scope, key)
    - Update `Ticket` and `TicketReply` fillable/casts
    - _Requirements: 2.6, 4.8, 1.7_

  - [ ] 1.2 Create `config/integrations.php` and `EventsCategoryMapper`
    - Config keys under `events`: `category_map` (eight rows from design D2), `allowed_ips`, `webhook_url`, `webhook_secret`, `staff_team_label`
    - Mapper returns `request_category`, `ticket_type` and `priority`; unknown keys fall back to `other` and log a warning
    - _Requirements: 4.4, 1.6, 6.5_

  - [ ]* 1.3 Write property test for category mapping
    - **Property 7: Category mapping is total**
    - **Validates: Requirements 4.4**

- [ ] 2. CRM Integration_API security layer
  - [ ] 2.1 Implement `EventsSignature` service and `VerifyEventsSignature` middleware
    - Sign `timestamp . '.' . rawBody` with HMAC-SHA256 as `sha256=…`; verify with `hash_equals` and a 300 second window
    - Apply to write methods only; respond 401 with no data change on failure
    - _Requirements: 1.8_

  - [ ] 2.2 Implement integration identity, token command and access middleware
    - `EventsIntegrationUserResolver` modelled on `McpBotUserResolver` (non-admin, login disabled)
    - `integrations:events-token` Artisan command issuing a Sanctum token with abilities `events:customers:write`, `events:tickets:write`, `events:tickets:read`, printed once
    - `EnsureEventsIntegrationToken` (403 for admin tokens), `RestrictEventsIntegrationIp` (exact and CIDR, open when list empty) and the `events-integration` rate limiter (120 per minute)
    - _Requirements: 1.2, 1.3, 1.4, 1.5, 1.6_

  - [ ] 2.3 Implement `EventsIdempotency` middleware and prune command
    - Insert-first flow: replay stored 2xx with `Idempotent-Replay: true`; 422 on hash mismatch; 409 with `Retry-After` when in flight; delete row on non-2xx
    - Prune command deleting keys older than 7 days, registered in the CRM scheduler
    - _Requirements: 1.7_

  - [ ] 2.4 Create `routes/integrations_events.php` and load it
    - Prefix `api/integrations/events` with middleware order: `EnsureHttps`, throttle, IP restriction, `auth:sanctum`, integration token check, then signature and idempotency on write methods
    - Register the group from `routes/api.php` or `bootstrap/app.php`
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8_

  - [ ]* 2.5 Write property tests for security primitives
    - **Property 1: Signature round trip and tamper rejection**
    - **Property 3: IP allow list**
    - **Validates: Requirements 1.8, 1.6**

  - [ ]* 2.6 Write feature tests for the Integration_API gate
    - 401 without token, 403 wrong ability, 403 admin Sanctum token, 429 after 120 requests, IP allow list, invalid signature, idempotent replay returns the same response with no duplicate record
    - **Property 2: Idempotent writes**
    - **Validates: Requirements 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 11.4**

- [ ] 3. CRM customer, ticket and reply endpoints
  - [ ] 3.1 Implement `EventsCustomerUpserter` and customer endpoints
    - `POST /customers/upsert`: resolve by `external_ref` (`events:{id}`), then case-insensitive email (also checking non-admin user emails) when the match has a null `external_ref`, otherwise create; handle unique violation by re-reading
    - `PATCH /customers/{company_id}` updates only keys present in the request
    - Form Requests for validation; return `{ company_id, action }`
    - _Requirements: 2.2, 2.3, 2.4, 2.6, 3.2_

  - [ ]* 3.2 Write tests for customer upsert and patch
    - **Property 4: Customer upsert resolution**
    - **Property 6: Partial customer patch**
    - **Validates: Requirements 2.2, 2.3, 2.4, 3.2, 11.5**

  - [ ] 3.3 Implement `EventsTicketPresenter` and ticket create/read endpoints
    - Presenter queries replies with `is_internal = false` at query level, never loads activities, derives author type, and returns staff first name or team label with no staff email
    - `POST /tickets` maps category, calls `TicketService::create()` with the integration user, sets source, requester and consent columns in one transaction, honours `notify_customer`
    - `GET /tickets/{ticket_id}` and `GET /tickets?ids[]=&since=` (max 50 ids), scoped to `source = 'events'`; mismatched `company_id` on writes returns 404
    - _Requirements: 4.2, 4.3, 4.8, 4.9, 6.2, 6.5, 8.7_

  - [ ] 3.4 Implement reply endpoint and `TicketService` changes
    - `POST /tickets/{ticket_id}/replies` creates a public reply with author name, email and Events user id via `TicketService::reply()`
    - Reopen `Pending` and `Closed` tickets to `Open` only for the integration user, with a `status_changed` activity
    - Skip customer emails when `ticket.source === 'events'`; add `CustomerRepliedNotification` for the assigned staff member or all admins
    - _Requirements: 7.3, 7.4, 7.7, 4.9_

  - [ ]* 3.5 Write tests for tickets, replies and presenter
    - **Property 10: Internal notes never cross the boundary**
    - **Property 11: Reply presentation**
    - **Property 13: Customer reply attribution and reopen**
    - Feature tests: ticket created via `TicketService` with admin notification, `notify_customer = false` sends no mail, staff notified on customer reply
    - **Validates: Requirements 4.2, 4.9, 6.1, 6.2, 6.4, 6.5, 7.3, 7.4, 7.7, 11.4**

- [ ] 4. CRM outbound webhooks
  - [ ] 4.1 Implement `DeliverEventsWebhookJob` and observers
    - `TicketObserver::updated` dispatches `ticket.status_changed` for `source = 'events'` tickets when status changed
    - `TicketReplyObserver::created` dispatches `ticket.reply_added` for non-internal staff replies on Events tickets only
    - Job signs the payload (design webhook format), `tries = 5`, `backoff = [30, 120, 600, 1800, 3600]`, 10 second timeout; retry on 401 and 5xx, success on 2xx including 202; log ticket id only on final failure
    - _Requirements: 8.1, 8.2, 8.6_

  - [ ]* 4.2 Write tests for webhook dispatch and delivery
    - Status change and staff reply dispatch; customer and internal replies do not; non-Events tickets do not; retry configuration
    - **Validates: Requirements 8.1, 8.2, 8.6**

- [ ] 5. Checkpoint - CRM side complete
  - Ensure all CRM tests pass, ask the user if questions arise.

### Part B: Events app

- [ ] 6. Events configuration and CRM client
  - [ ] 6.1 Add CRM configuration
    - Add `services.crm` to `config/services.php` (enabled, base_url, token, webhook_secret, timeout)
    - Document `CRM_ENABLED`, `CRM_BASE_URL`, `CRM_API_TOKEN`, `CRM_WEBHOOK_SECRET`, `CRM_TIMEOUT` in `.env.example` without secrets
    - _Requirements: 10.1, 10.2_

  - [ ] 6.2 Implement `CrmSignature`, `CrmDisabledException` and `CrmClient`
    - `CrmSignature::sign/verify` matching the CRM scheme
    - `CrmClient`: timeout `min(config, 10)`, bearer token, signed body, `Idempotency-Key` on writes, `isEnabled()`, refuse non-HTTPS URLs in production with a logged configuration error, `redact()` for logs and exceptions
    - Methods: `upsertCustomer`, `patchCustomer`, `createTicket`, `getTicket`, `getTickets`, `postReply`
    - _Requirements: 10.3, 10.4, 10.5, 10.6, 10.7, 1.8_

  - [ ]* 6.3 Write property tests for client safety
    - **Property 1: Signature round trip and tamper rejection**
    - **Property 16: Effective timeout bound**
    - **Property 17: Secrets never leak**
    - **Property 18: HTTPS required in production**
    - Feature test: disabled flag makes no HTTP calls
    - **Validates: Requirements 1.8, 8.4, 10.3, 10.4, 10.6, 10.7, 11.1**

- [ ] 7. Events data layer
  - [ ] 7.1 Add migrations and models
    - `companies.crm_customer_id`; `support_requests` columns `crm_ticket_id` (unique), `crm_status`, `crm_synced_at`, `crm_updated_at`, `crm_sync_state`
    - New `support_request_replies` table, `SupportRequestReply` model (`BelongsToCompany`) with factory, and `SupportRequest` relationship
    - _Requirements: 2.5, 4.5, 6.3, 7.2_

- [ ] 8. Applying CRM updates
  - [ ] 8.1 Implement `CrmStatusMapper`
    - Map `Open`→open, `In Progress`→in_progress, `Pending`→resolved, `Closed`→closed, other→open with a warning
    - _Requirements: 5.4_

  - [ ] 8.2 Implement `CrmTicketUpdater` and `SupportReplyReceivedMail`
    - Single transactional path: ignore unknown tickets and stale `ticket_updated_at`, update status fields and `resolved_at`, drop internal replies, `firstOrCreate` on `crm_reply_id`
    - Email the submitting user with a Support_Portal link only for newly created staff replies
    - _Requirements: 6.3, 8.3, 8.7, 8.10_

  - [ ]* 8.3 Write tests for status mapping and update application
    - **Property 8: Status mapping is total**
    - **Property 10: Internal notes never cross the boundary**
    - **Property 14: CRM update application is idempotent and source independent**
    - **Validates: Requirements 5.4, 6.3, 8.3, 8.7, 8.10**

- [ ] 9. Outbound sync jobs
  - [ ] 9.1 Implement sync jobs, `CompanyObserver` and `SupportTicketCreator` hook
    - `SyncCompanyToCrmJob`, `SyncSupportRequestToCrmJob`, `SyncSupportReplyToCrmJob` with `tries = 5`, backoff, deterministic Idempotency-Keys, `failed()` logging id and redacted error, clean skip when disabled
    - No-retry handling for 422 (mark `failed`); ticket job upserts customer first; reply job ensures ticket first
    - `CompanyObserver`: created dispatches upsert; updated dispatches patch only when synced fields changed, falling back to upsert without `crm_customer_id`; register the observer
    - Add `SyncSupportRequestToCrmJob::dispatch($ticket->id)->afterCommit()` in `SupportTicketCreator::raise` with `notify_customer = false`
    - _Requirements: 2.1, 2.5, 2.7, 2.8, 3.1, 3.3, 3.4, 3.5, 4.1, 4.3, 4.5, 4.6, 4.7, 10.3, 10.5_

  - [ ]* 9.2 Write tests for sync behaviour
    - **Property 5: Sync dispatch only for synced fields**
    - Feature tests with `Http::fake()`: signup upsert, `crm_customer_id` stored, signup succeeds when CRM is down, ticket payload contents, confirmation shown when CRM is down
    - **Validates: Requirements 2.1, 2.5, 2.7, 3.1, 3.4, 3.5, 4.1, 4.3, 4.5, 4.6, 4.7, 11.2**

- [ ] 10. Support_Portal
  - [ ] 10.1 Implement `SupportRequestController`, routes and views
    - `index` (20 per page, company-filtered with `withoutGlobalScopes()`), `show`, `reply` routes under the support group with `withoutMiddleware('dashboard.tenant')`
    - `show`: original message, status, replies ordered by `posted_at, id`, "sending" indicator without `crm_ticket_id`, failed-reply marker, refresh from CRM when stale over 5 minutes and open/in_progress (5 second timeout, failures swallowed)
    - `reply`: validate `required|string|max:5000`, reject when `closed`, store pending reply, dispatch `SyncSupportReplyToCrmJob`
    - Add navigation link to the list; follow existing Blade conventions
    - _Requirements: 5.1, 5.2, 5.3, 5.5, 5.6, 6.1, 6.4, 7.1, 7.2, 7.5, 7.6, 8.9_

  - [ ]* 10.2 Write tests for the Support_Portal
    - **Property 9: Company isolation**
    - **Property 12: Reply message validation**
    - Feature tests: pagination, closed rejection, failed reply marker, stale refresh, cross-company list/show/reply returns 404
    - **Validates: Requirements 5.1, 5.2, 5.3, 5.6, 7.1, 7.5, 7.6, 8.9, 11.3**

- [ ] 11. Webhook receiver
  - [ ] 11.1 Implement `VerifyCrmSignature` and `CrmWebhookController`
    - `POST /crm/webhook/tickets` outside CSRF, auth and tenant middleware (like the Stripe webhook), with throttling
    - 401 on invalid or missing signature with no change; 202 for unknown `ticket_id`; otherwise apply via `CrmTicketUpdater`
    - _Requirements: 8.3, 8.4, 8.5_

  - [ ]* 11.2 Write tests for the webhook receiver
    - Valid signature updates status and replies without duplicates, invalid and missing signature return 401, unknown ticket returns 202
    - **Validates: Requirements 8.3, 8.4, 8.5, 11.2**

- [ ] 12. Poller and Backfill
  - [ ] 12.1 Implement `crm:poll-tickets`
    - Artisan command in `routes/console.php` wrapped in `CronRuns::track`; select open/in_progress requests with `crm_ticket_id`, chunk by 50, apply via `CrmTicketUpdater`, log chunk failures and continue, no-op when disabled
    - Document the 10 minute cron entry in `DEPLOYMENT.md` only if that file already lists cron jobs
    - _Requirements: 8.7, 8.8, 10.3_

  - [ ] 12.2 Implement `crm:backfill {--dry-run}`
    - Upsert companies without `crm_customer_id` (skip those without email), create tickets for requests without `crm_ticket_id` with `notify_customer = false`
    - `--dry-run` reports without calling `CrmClient`; print synced/skipped/failed counts; non-zero exit on failures
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6_

  - [ ]* 12.3 Write tests for Poller and Backfill
    - **Property 15: Backfill is idempotent**
    - Feature tests: poller applies updates and survives a failing chunk, backfill dry run sends no writes, second run syncs zero, counts printed, no notification emails requested
    - **Validates: Requirements 8.7, 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 11.2**

- [ ] 13. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass in both repositories, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for a faster MVP
- Part A must be deployed and an Integration_Credential issued before Part B is enabled in any environment; `CRM_ENABLED` defaults to false
- The `Pending`→`resolved` status mapping is a design judgement call; adjust only `CrmStatusMapper` if CRM usage differs
- Each task references specific requirements for traceability

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2"] },
    { "id": 1, "tasks": ["1.3", "2.1", "2.2", "2.3"] },
    { "id": 2, "tasks": ["2.4"] },
    { "id": 3, "tasks": ["2.5", "3.1"] },
    { "id": 4, "tasks": ["2.6", "3.2", "3.3"] },
    { "id": 5, "tasks": ["3.4"] },
    { "id": 6, "tasks": ["3.5", "4.1"] },
    { "id": 7, "tasks": ["4.2", "6.1"] },
    { "id": 8, "tasks": ["6.2", "7.1"] },
    { "id": 9, "tasks": ["6.3", "8.1"] },
    { "id": 10, "tasks": ["8.2"] },
    { "id": 11, "tasks": ["8.3", "9.1"] },
    { "id": 12, "tasks": ["9.2", "10.1"] },
    { "id": 13, "tasks": ["10.2", "11.1"] },
    { "id": 14, "tasks": ["11.2", "12.1"] },
    { "id": 15, "tasks": ["12.2"] },
    { "id": 16, "tasks": ["12.3"] }
  ]
}
```
