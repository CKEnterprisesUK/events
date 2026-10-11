-- =============================================================================
-- 045_add_disabled_at_to_users.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Lets a Super_Admin reversibly disable a user account from the platform
--   surface — a block that keeps the row (and its audit history) but prevents
--   login and tears down any live session on the user's next request. Distinct
--   from a hard delete (removes the row) and from Company suspension (blocks a
--   whole Company's users at once). Adds a nullable timestamp to `users`:
--     * ADD `disabled_at` — when the account was disabled, NULL when active.
--       Reversible: a Super_Admin clears it to re-enable the account.
--
--   This is an incremental ALTER applied AFTER 005_create_users.sql: it assumes
--   the `users` table already exists with a `last_activity_at` column. Applied
--   on shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `users` table via mysqldump. Regenerate this file whenever the
--   corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events users
--
--   (migration: 2025_01_09_000000_add_disabled_at_to_users_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 044_add_stripe_fee_estimate_to_platform_settings.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   045 (initial) — add nullable `disabled_at` to `users`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `users`
  ADD COLUMN `disabled_at` timestamp NULL DEFAULT NULL AFTER `last_activity_at`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
-- Mirrors the Laravel migration filename so migration state stays consistent
-- if migrations are ever run against this database later.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_09_000000_add_disabled_at_to_users_table', 13);
