-- =============================================================================
-- 028_add_agreed_to_terms_at_to_users.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Records acceptance of the Platform Terms & Conditions of Events by CK
--   Enterprises UK. Adds a nullable timestamp to `users`:
--     * ADD `agreed_to_terms_at` — when the user accepted the Platform Terms.
--       Captured at self-signup (the Owner must agree before the account is
--       created). NULL for users who never explicitly accepted (invited users,
--       pre-existing accounts, out-of-band Super_Admins).
--
--   This is an incremental ALTER applied AFTER 005_create_users.sql: it assumes
--   the `users` table already exists with a `password` column. Applied on
--   shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
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
--   (migration: 2024_01_01_002700_add_agreed_to_terms_at_to_users_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 027_create_legal_documents.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   028 (initial) — add nullable `agreed_to_terms_at` to `users`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `users`
  ADD COLUMN `agreed_to_terms_at` timestamp NULL DEFAULT NULL AFTER `password`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002700_add_agreed_to_terms_at_to_users_table', 11);
