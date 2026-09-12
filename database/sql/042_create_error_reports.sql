-- =============================================================================
-- 042_create_error_reports.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Capture uncaught server errors (HTTP 500s) in production. With
--   `APP_DEBUG=false` a visitor is never shown a raw stack trace; instead the
--   exception handler stores the raw diagnostic detail here and shows the
--   customer a short, quotable `reference` (e.g. `ERR-3F9A2B7C`) on a branded
--   error page. A Super_Admin looks the reference up on `/admin/errors` to see
--   the full exception behind it.
--
--   Like `audit_logs` (029), this table is NOT tenant-scoped by the global
--   company_id scope: an error may occur on the platform surface, on a webhook,
--   or before a tenant is resolved, so it may have no Company and no user.
--   `company_id`/`user_id` are plain nullable columns (FK → companies/users ON
--   DELETE SET NULL) and the admin surface reads across every tenant.
--
--   Columns:
--     * `reference`       — short, unique, customer-facing lookup code (random,
--                           not sequential, so it leaks no volume information).
--     * `company_id`      — tenant in context (NULL = platform/system/pre-tenant).
--     * `user_id`         — authenticated user in context (NULL = guest/system).
--     * `exception_class` — thrown exception's class.
--     * `message`         — the exception message.
--     * `file` / `line`   — where it was thrown.
--     * `status_code`     — HTTP status served to the client (typically 500).
--     * `method` / `url`  — the request that triggered it.
--     * `ip_address`      — request IP (IPv4/IPv6).
--     * `user_agent`      — request User-Agent.
--     * `trace`           — full stack trace (longtext).
--     * `context`         — small PII-minimised JSON payload.
--     * `resolved_at`     — stamped when a Super_Admin marks it dealt with.
--     * `created_at`/`updated_at` — created drives the listing/prune; updated
--                           moves when the report is resolved.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `error_reports` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events error_reports
--
--   (migration: 2025_01_06_000000_create_error_reports_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 041_add_abuse_to_support_requests_category.sql.
--   Requires `companies` (002) and `users` (005) to already exist for the FKs.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   042 (initial) — create `error_reports`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `error_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(32) NOT NULL,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `exception_class` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `file` varchar(255) DEFAULT NULL,
  `line` int(10) unsigned DEFAULT NULL,
  `status_code` smallint(5) unsigned NOT NULL DEFAULT 500,
  `method` varchar(10) DEFAULT NULL,
  `url` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `trace` longtext DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `error_reports_reference_unique` (`reference`),
  KEY `error_reports_company_id_index` (`company_id`),
  KEY `error_reports_user_id_index` (`user_id`),
  KEY `error_reports_created_at_index` (`created_at`),
  CONSTRAINT `error_reports_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `error_reports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_06_000000_create_error_reports_table', 13);
