# Versioned raw SQL (phpMyAdmin deployment)

Production runs on shared cPanel hosting with **no SSH access** — only
phpMyAdmin. Laravel migrations remain the single source of truth and run
locally/CI, but every schema change also produces a matching, generated raw
`.sql` file here so the schema can be applied on prod purely by pasting SQL.

## How to apply on prod

Paste the files below into phpMyAdmin (Import / SQL tab) **in filename order**.
Each file's header documents exactly which migration(s) it was generated from
and the command used to regenerate it.

## Rules

- **Generated, never hand-written.** DDL is produced from the migrated MySQL
  schema (`mysqldump` / `php artisan schema:dump`). Regenerate the affected file
  whenever its migration changes so the SQL stays byte-consistent with the
  migrations.
- Files are versioned with an incrementing numeric prefix (`NNN_...`).
- Each file records its migration(s) in the Laravel `migrations` ledger so the
  app's migration state stays consistent if migrations are later run against the
  database.

## Files

| File | Applied? | Description | Generated from |
| --- | --- | --- | --- |
| `001_create_queue_tables.sql` | ☐ | `migrations`, `jobs`, `job_batches`, `failed_jobs` tables (DB queue foundation). | `0001_01_01_000002_create_jobs_table` + framework `migrations` table |
| `002_create_companies.sql` | ☐ | `companies` table (tenant root: slug, status, fee handling, Stripe, branding). | `2024_01_01_000100_create_companies_table` |
| `003_create_platform_settings.sql` | ☐ | `platform_settings` table (Platform-wide config: `global_fee_percent`). | `2024_01_01_000900_create_platform_settings_table` |
| `004_seed_platform_settings.sql` | ☐ | Seed the single default `platform_settings` row (`global_fee_percent` = 5.00). | `Database\Seeders\PlatformSettingSeeder` |
| `005_create_users.sql` | ☐ | `users` table (Company_Users + Super_Admins: four-role enum, `company_id` FK NULL, `last_activity_at`, single-Owner invariant via generated-column UNIQUE index). Apply after `002_create_companies.sql`. | `0001_01_01_000000_create_users_table` + FK from `2024_01_01_000100_create_companies_table` |
| `006_create_events.sql` | ☐ | `events` table (Company-owned events: `company_id` FK, details, `capacity` NULL = unlimited, `is_published`, per-Event branding overrides). Apply after `002_create_companies.sql`. | `2024_01_01_001000_create_events_table` |
| `007_create_invitations.sql` | ☐ | `invitations` table (Company-owned invitations: `company_id` FK, `email`, `role` enum excluding owner, unique `token`, `accepted_at` NULL while pending, `expires_at`). Apply after `002_create_companies.sql`. | `2024_01_01_001100_create_invitations_table` |
| `008_create_ticket_types.sql` | ☐ | `ticket_types` table (Company-owned ticket categories: `company_id` + `event_id` FKs, `name` VARCHAR(100), `price_minor` integer minor units (0 = free), `capacity`, `sold_count`/`reserved_count` default 0, `sale_starts_at`/`sale_ends_at` window). Apply after `002_create_companies.sql` and `006_create_events.sql`. | `2024_01_01_001200_create_ticket_types_table` |
| `009_create_orders.sql` | ☐ | `orders` table (Company-owned Customer checkouts: `company_id` + `event_id` FKs, `order_reference` UNIQUE across Platform, `status` enum default `reserved`, money fields in integer minor units default 0, `fee_handling_mode` snapshot, `reserved_until` 900s window, nullable Stripe linkage + scan columns with `scanned_by` FK to `users` ON DELETE SET NULL). Apply after `002_create_companies.sql`, `005_create_users.sql`, and `006_create_events.sql`. | `2024_01_01_001300_create_orders_table` |
| `010_create_tickets.sql` | ☐ | `tickets` table (Company-owned per-ticket rows: `company_id` + `order_id` + `ticket_type_id` FKs cascading on delete, `status` enum(`valid`,`voided`) default `valid`). Apply after `008_create_ticket_types.sql` and `009_create_orders.sql`. | `2024_01_01_001400_create_tickets_table` |
| `011_create_order_consents.sql` | ☐ | `order_consents` table (Company-owned consent capture: `company_id` + `order_id` FKs cascading on delete, `consent_key`, `accepted` boolean, `captured_at` timestamp). Apply after `009_create_orders.sql`. | `2024_01_01_001500_create_order_consents_table` |
| `012_create_processed_webhooks.sql` | ☐ | `processed_webhooks` table (webhook idempotency ledger: `stripe_event_id` UNIQUE, `type`, `processed_at` timestamp; not Company-owned, no foreign keys). Apply after `001_create_queue_tables.sql`. | `2024_01_01_001600_create_processed_webhooks_table` |
| `013_add_fulfilled_at_to_orders.sql` | ☐ | ALTER `orders` to add nullable `fulfilled_at` timestamp (fulfilment idempotency guard: capacity committed reserved→sold, QR issued, ticket email enqueued exactly once on confirmation). Apply after `009_create_orders.sql`. | `2024_01_01_001700_add_fulfilled_at_to_orders_table` |
| `014_create_sessions.sql` | ☐ | `sessions` table (database session driver store: `id` PK, nullable `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`). Required because `SESSION_DRIVER=database`; every `web` route reads/writes it via StartSession. Apply after `005_create_users.sql`. | `0001_01_01_000000_create_users_table` (framework `sessions` table) |
| `015_create_cache_tables.sql` | ☐ | `cache` + `cache_locks` tables (database cache store: `key` PK, `value`, `expiration`; locks add `owner`). Required because `CACHE_STORE=database`; the `ResolveTenant` middleware memoises Company-by-slug lookups via `cache()->remember()` on every storefront request. | `0001_01_01_000001_create_cache_table` |
| `016_add_contacts_to_companies.sql` | ☐ | ALTER `companies` to add nullable `support_email` (public support contact) and `gdpr_contact_email` (GDPR/data-protection contact) columns, managed by the Owner and part of the dashboard onboarding checklist. Apply after `002_create_companies.sql`. | `2024_01_01_000101_add_contacts_to_companies_table` |
| `017_add_legal_details_to_companies.sql` | ☐ | ALTER `companies` to widen `status` enum (add `pending`/`closed`, default stays `active`) and add legal/registration + address columns (`legal_name`, `trading_name`, `organisation_type` enum, `company_number`, `charity_number`, `website`, `email`, `phone`, `address_line_1`, `address_line_2`, `city`, `postcode`, `country` char(2) default `GB`) captured at signup and managed on the branding/settings surface. Apply after `002_create_companies.sql` and `016_add_contacts_to_companies.sql`. | `2024_01_01_000102_add_legal_details_to_companies_table` |
| `018_add_poster_to_companies.sql` | ☐ | ALTER `companies` to add a nullable `poster_path` column (optional Storefront hero/poster image shown at the top of the public Storefront, alongside the Company `logo_path`). Apply after `002_create_companies.sql`. Ledger row for the shared migration is recorded in `019_add_poster_to_events.sql`. | `2024_01_01_001800_add_poster_to_branding` |
| `019_add_poster_to_events.sql` | ☐ | ALTER `events` to add a nullable `poster_path` column (optional per-Event poster/hero image shown on the Event page and as a Storefront thumbnail, overriding the Company hero; alongside the per-Event `logo_path`). Apply after `006_create_events.sql` and `018_add_poster_to_companies.sql`. Records the shared migration in the `migrations` ledger once. | `2024_01_01_001800_add_poster_to_branding` |
| `023_add_storefront_profile_to_companies.sql` | ☐ | ALTER `companies` to add nullable public Storefront profile columns: `about_text` (an "about the company" description) plus external links `facebook_url`, `instagram_url`, `x_url`, `linkedin_url`, `terms_url` (organiser's own Terms & Conditions page) and `privacy_url` (organiser's own Privacy Notice page). The organisation `website` column already exists (017) and is reused. Apply after `002_create_companies.sql`. | `2024_01_01_002200_add_storefront_profile_to_companies_table` |
| `024_add_box_office_role.sql` | ☐ | ALTER `users` and `invitations` to add the `box_office` Company role to the `role` enum (a cut-down Admin that runs events/ticketing/orders but cannot change company settings, users, Stripe, billing, or GDPR). `users.role` keeps `owner`; `invitations.role` stays owner-excluded. Apply after `005_create_users.sql` and `007_create_invitations.sql`. | `2024_01_01_002300_add_box_office_role` |
| `024_replace_legal_urls_with_privacy_text_on_companies.sql` | ☐ | ALTER `companies` to move legal content in-app: add nullable `privacy_text` (in-app Privacy Notice shown to the customer on request at checkout, mirroring the existing `terms_text`) and drop the external-link columns `terms_url` and `privacy_url`. Apply after `023_add_storefront_profile_to_companies.sql`. | `2024_01_01_002300_replace_legal_urls_with_privacy_text_on_companies` |
