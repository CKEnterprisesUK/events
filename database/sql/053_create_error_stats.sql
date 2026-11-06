-- =============================================================================
-- 053_create_error_stats.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Hourly counter of HTTP error responses (403, 404, 500) backing the baseline
--   graph on /admin/errors and the spike detector (`errors:check-spikes`) that
--   emails Super_Admins when an hour runs well above the usual level. Counts
--   only: no URLs, users or other PII. NOT tenant scoped.
--
--   Columns:
--     * `bucket_hour` — start of the hour (app timezone).
--     * `status_code` — 403, 404 or 500 (any 5xx is counted as 500).
--     * `hits`        — responses served in that hour.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration. Regenerate this file whenever the
--   migration changes so the SQL stays consistent:
--
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data events error_stats
--
--   (migration: 2026_11_07_000000_create_error_stats_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 052_seed_help_content.sql. The final
--   INSERT records the migration in the `migrations` ledger.
--
-- Changelog:
--   053 (initial) — create `error_stats`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `error_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bucket_hour` datetime NOT NULL,
  `status_code` smallint(5) unsigned NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `error_stats_bucket_hour_status_code_unique` (`bucket_hour`,`status_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_07_000000_create_error_stats_table', 16);
