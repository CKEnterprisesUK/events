-- =============================================================================
-- 024_add_box_office_role.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds the `box_office` Company role to the `role` enums on `users` and
--   `invitations`. Box_Office is a cut-down Admin: it runs the box office
--   (events, ticket types, orders incl. cancel/refund/comp) but is NOT trusted
--   with Company settings, users, Stripe, billing, or GDPR handling.
--
--   * `users.role` keeps `owner` in its value set (owners are seeded /
--     transferred, never invited) and gains `box_office`.
--   * `invitations.role` continues to EXCLUDE `owner` (the Owner is not
--     invitable, Requirement 4.6) and gains `box_office` alongside the other
--     invitable roles.
--
--   This is an incremental ALTER applied AFTER 005_create_users.sql and
--   007_create_invitations.sql: it assumes those tables already exist. Applied
--   on shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order.
--
-- Covers changes:
--   * users.role         (enum gains `box_office`)
--   * invitations.role   (enum gains `box_office`)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `users`/`invitations` tables via mysqldump. Regenerate this
--   file whenever the corresponding migration changes so the SQL stays
--   consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events users invitations
--
--   (migration: 2024_01_01_002300_add_box_office_role)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 005_create_users.sql and
--   007_create_invitations.sql. The final INSERT records the migration in the
--   `migrations` ledger so the app's migration state stays consistent if
--   migrations are ever run against this database later.
--
-- Changelog:
--   024 (initial) — add `box_office` to the `role` enum on `users` and
--        `invitations`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `users`
  MODIFY `role` enum('owner','admin','box_office','accountant','scanner') DEFAULT NULL;

ALTER TABLE `invitations`
  MODIFY `role` enum('admin','box_office','accountant','scanner') NOT NULL;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002300_add_box_office_role', 8);
