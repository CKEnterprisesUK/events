-- =============================================================================
-- 054_create_cron_runs.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   One row per scheduled (cron) command recording its last run: start/finish,
--   success, duration, a one-line summary of what it did and the last error.
--   Read by the Super_Admin "System health" page to show whether each cron job
--   is running on time. NOT tenant scoped.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration. Regenerate this file whenever the
--   migration changes:
--
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data events cron_runs
--
--   (migration: 2026_11_07_000001_create_cron_runs_table)
--
-- Applying on prod:
--   Paste into phpMyAdmin after 053_create_error_stats.sql.
--
-- Changelog:
--   054 (initial) — create `cron_runs`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `cron_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `last_started_at` timestamp NULL DEFAULT NULL,
  `last_finished_at` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `last_status` varchar(16) NOT NULL DEFAULT 'running',
  `last_duration_ms` int(10) unsigned DEFAULT NULL,
  `last_actions` int(10) unsigned NOT NULL DEFAULT 0,
  `last_summary` text DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `runs_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `failures_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cron_runs_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_07_000001_create_cron_runs_table', 16);
