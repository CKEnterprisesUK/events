-- =============================================================================
-- 033_create_support_requests.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Backs the in-dashboard "Contact support" form. A Company_User raises a
--   ticket with the platform operator (Events by CK Enterprises UK / the
--   Super_Admins) when the Help & Knowledge portal has not resolved their issue.
--
--   This is a Company-owned table (rows carry `company_id` and are isolated to
--   the raising tenant by the app's global tenant scope). The raising user is
--   recorded on `user_id` ON DELETE SET NULL so the ticket survives the user
--   being removed/anonymised.
--
--   Columns:
--     * `company_id`        — owning Company (FK, ON DELETE CASCADE).
--     * `user_id`           — raising user (FK, ON DELETE SET NULL).
--     * `category`          — triage bucket (account/events/orders/payments/
--                             billing/technical/other). DEFAULT `other`.
--     * `subject`           — the user's one-line summary.
--     * `message`           — the full description.
--     * `status`            — open/in_progress/resolved/closed. DEFAULT `open`.
--     * `access_consent`    — whether the user granted "allow CK Enterprises to
--                             access my account to assist with this request".
--                             A per-ticket authorisation for Super_Admin access.
--     * `access_consent_at` — when that consent was granted (NULL = never).
--     * `resolved_at`       — when the ticket reached a terminal state.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `support_requests` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events support_requests
--
--   (migration: 2024_01_01_003200_create_support_requests_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 032_add_sponsor_details_to_events.sql.
--   Requires `companies` (002) and `users` (005) to already exist for the FKs.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   033 (initial) — create `support_requests`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `support_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `category` enum('account','events','orders','payments','billing','technical','other') NOT NULL DEFAULT 'other',
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','in_progress','resolved','closed') NOT NULL DEFAULT 'open',
  `access_consent` tinyint(1) NOT NULL DEFAULT 0,
  `access_consent_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `support_requests_company_id_status_index` (`company_id`,`status`),
  KEY `support_requests_user_id_foreign` (`user_id`),
  CONSTRAINT `support_requests_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `support_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_003200_create_support_requests_table', 11);
