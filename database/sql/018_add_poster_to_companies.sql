-- =============================================================================
-- 018_add_poster_to_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds a nullable `poster_path` column to the `companies` table: an optional
--   Storefront hero/poster image shown at the top of the public Company
--   Storefront, sitting alongside the existing `logo_path` (the Company mark)
--   so an organiser can present both a logo and imagery independently. NULL
--   until the Owner uploads a Storefront poster on the branding surface.
--
--   This is an incremental ALTER applied AFTER 002_create_companies.sql: it
--   assumes the `companies` table already exists. Applied on shared cPanel
--   hosting by pasting this file into phpMyAdmin (Import / SQL tab) in filename
--   order.
--
-- Covers changes:~
--   * companies.poster_path  (nullable Storefront hero/poster image path)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `companies` table via mysqldump. Regenerate this file whenever
--   the corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events companies
--
--   (migration: 2024_01_01_001800_add_poster_to_branding)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql. The final
--   INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later. NOTE: the same migration also alters `events`; that change
--   ships in 019_add_poster_to_events.sql, and the ledger row is recorded there
--   so the migration is registered exactly once.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  ADD COLUMN `poster_path` varchar(255) DEFAULT NULL AFTER `logo_path`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
