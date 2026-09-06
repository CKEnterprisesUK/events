-- =============================================================================
-- 034_create_event_sponsors.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   A repeatable per-Event sponsors list, replacing the two fixed top/bottom
--   sponsor slots that previously lived as columns on `events`
--   (026_add_ticket_design_to_events.sql + 032_add_sponsor_details_to_events.sql).
--
--   Each row is one sponsor: a logo image plus optional store-page details
--   (name, website, bio) and a per-sponsor `on_ticket` flag choosing whether
--   the logo is printed on the ticket PDF. `sort_order` drives the display
--   order on the public event/storefront page. The number of sponsors per event
--   is unlimited for the public page; the application caps how many may be
--   flagged `on_ticket` (enforced in the controller, not the schema).
--
--   This is a Company+Event-owned table (rows carry `company_id` and `event_id`
--   and are isolated to the owning tenant by the app's global tenant scope).
--
--   Columns:
--     * `company_id`  — owning Company (FK, ON DELETE CASCADE).
--     * `event_id`    — owning Event   (FK, ON DELETE CASCADE).
--     * `image_path`  — sponsor logo image path on the public disk (required).
--     * `name`        — sponsor / company name (store page). NULL = omit.
--     * `website_url` — sponsor external URL (store page link). NULL = no link.
--     * `bio`         — short blurb (store page only). NULL = omit.
--     * `on_ticket`   — whether this sponsor's logo prints on the ticket PDF.
--                       DEFAULT 0. The app caps the number of on_ticket rows.
--     * `sort_order`  — display order on the public page (ascending). DEFAULT 0.
--
--   AFTER creating the table, this script backfills it from the legacy
--   `events.sponsor_top_*` / `events.sponsor_bottom_*` columns (top first, then
--   bottom) so existing sponsors are preserved. The legacy columns are LEFT IN
--   PLACE (unused) — a later migration may drop them once this has bedded in.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `event_sponsors` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events event_sponsors
--
--   (migration: 2024_01_01_003300_create_event_sponsors_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 033_create_support_requests.sql.
--   Requires `companies` (002) and `events` to already exist for the FKs, and
--   the legacy `events.sponsor_*` columns (026 + 032) for the backfill.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   034 (initial) — create `event_sponsors` and backfill from the legacy
--        events.sponsor_top_* / sponsor_bottom_* columns.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `event_sponsors` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `website_url` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `on_ticket` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_sponsors_event_id_sort_order_index` (`event_id`,`sort_order`),
  KEY `event_sponsors_company_id_foreign` (`company_id`),
  CONSTRAINT `event_sponsors_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_sponsors_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Backfill: legacy TOP sponsor (sort_order 0) for every event that has one.
INSERT INTO `event_sponsors`
  (`company_id`, `event_id`, `image_path`, `name`, `website_url`, `bio`, `on_ticket`, `sort_order`, `created_at`, `updated_at`)
SELECT
  `company_id`, `id`, `sponsor_top_path`, `sponsor_top_name`, `sponsor_top_website`,
  `sponsor_top_bio`, COALESCE(`sponsor_top_on_ticket`, 0), 0, NOW(), NOW()
FROM `events`
WHERE `sponsor_top_path` IS NOT NULL AND `sponsor_top_path` <> '';

-- Backfill: legacy BOTTOM sponsor (sort_order 1) for every event that has one.
INSERT INTO `event_sponsors`
  (`company_id`, `event_id`, `image_path`, `name`, `website_url`, `bio`, `on_ticket`, `sort_order`, `created_at`, `updated_at`)
SELECT
  `company_id`, `id`, `sponsor_bottom_path`, `sponsor_bottom_name`, `sponsor_bottom_website`,
  `sponsor_bottom_bio`, COALESCE(`sponsor_bottom_on_ticket`, 0), 1, NOW(), NOW()
FROM `events`
WHERE `sponsor_bottom_path` IS NOT NULL AND `sponsor_bottom_path` <> '';

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_003300_create_event_sponsors_table', 12);
