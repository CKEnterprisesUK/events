-- =============================================================================
-- 019_add_poster_to_events.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds a nullable `poster_path` column to the `events` table: an optional
--   per-Event poster/hero image shown on the public Event page and as a
--   thumbnail on the Company Storefront listing, overriding the Company-level
--   hero (`companies.poster_path`) where set. It sits alongside the existing
--   per-Event `logo_path` override so an Event can present both a logo and
--   poster imagery independently. NULL = inherit the Company hero / show none.
--
--   This is an incremental ALTER applied AFTER 006_create_events.sql: it
--   assumes the `events` table already exists. Applied on shared cPanel hosting
--   by pasting this file into phpMyAdmin (Import / SQL tab) in filename order.
--
-- Covers changes:
--   * events.poster_path  (nullable per-Event poster/hero image path)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `events` table via mysqldump. Regenerate this file whenever the
--   corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events events
--
--   (migration: 2024_01_01_001800_add_poster_to_branding)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 006_create_events.sql (and after
--   018_add_poster_to_companies.sql, which carries the same migration's
--   `companies` change). The final INSERT records the migration in the
--   `migrations` ledger exactly once for the whole change.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `events`
  ADD COLUMN `poster_path` varchar(255) DEFAULT NULL AFTER `logo_path`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin. This
-- single migration alters both `companies` (018) and `events` (019); the ledger
-- row is recorded here, once, after both ALTERs have been applied.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001800_add_poster_to_branding', 5);
