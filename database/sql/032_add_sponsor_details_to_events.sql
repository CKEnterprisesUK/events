-- =============================================================================
-- 032_add_sponsor_details_to_events.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Per-sponsor metadata for the two Event sponsor slots added in
--   026_add_ticket_design_to_events.sql. Alongside each sponsor banner image
--   the organiser can now record a company name, a website link and a short
--   bio, and choose whether that sponsor's logo is printed on the ticket PDF:
--     * ADD `sponsor_top_name` / `sponsor_bottom_name`       — sponsor display/
--       company name, shown next to the logo on the public store page.
--     * ADD `sponsor_top_website` / `sponsor_bottom_website` — sponsor external
--       URL; the store-page logo/name links here when set.
--     * ADD `sponsor_top_bio` / `sponsor_bottom_bio`         — short blurb shown
--       alongside the logo on the public store page ONLY (not on the ticket).
--     * ADD `sponsor_top_on_ticket` / `sponsor_bottom_on_ticket` — whether the
--       sponsor's banner is printed on the ticket PDF. DEFAULT 1 (true) so
--       existing sponsor banners keep printing on tickets as before.
--
--   The name/website/bio surfaces are store-page only; the ticket PDF uses only
--   the image + the on_ticket toggle. When no sponsor banner is printed on the
--   ticket, the ticket falls back to the organiser's own logo.
--
--   This is an incremental ALTER applied AFTER 026_add_ticket_design_to_events.sql:
--   it assumes the `events` table already has `sponsor_top_path` and
--   `sponsor_bottom_path`. Applied on shared cPanel hosting by pasting this file
--   into phpMyAdmin (Import / SQL tab) in filename order.
--
-- Covers changes:
--   * events.sponsor_top_name          (nullable sponsor name)
--   * events.sponsor_top_website       (nullable sponsor website URL)
--   * events.sponsor_top_bio           (nullable sponsor bio text)
--   * events.sponsor_top_on_ticket     (bool, default 1: print on ticket)
--   * events.sponsor_bottom_name       (nullable sponsor name)
--   * events.sponsor_bottom_website    (nullable sponsor website URL)
--   * events.sponsor_bottom_bio        (nullable sponsor bio text)
--   * events.sponsor_bottom_on_ticket  (bool, default 1: print on ticket)
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
--   (migration: 2024_01_01_003100_add_sponsor_details_to_events)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 031_add_refunded_total_to_orders.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   032 (initial) — add per-sponsor name/website/bio/on_ticket columns for both
--        the top and bottom sponsor slots on `events`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `events`
  ADD COLUMN `sponsor_top_name` varchar(255) DEFAULT NULL AFTER `sponsor_top_path`,
  ADD COLUMN `sponsor_top_website` varchar(255) DEFAULT NULL AFTER `sponsor_top_name`,
  ADD COLUMN `sponsor_top_bio` text DEFAULT NULL AFTER `sponsor_top_website`,
  ADD COLUMN `sponsor_top_on_ticket` tinyint(1) NOT NULL DEFAULT 1 AFTER `sponsor_top_bio`,
  ADD COLUMN `sponsor_bottom_name` varchar(255) DEFAULT NULL AFTER `sponsor_bottom_path`,
  ADD COLUMN `sponsor_bottom_website` varchar(255) DEFAULT NULL AFTER `sponsor_bottom_name`,
  ADD COLUMN `sponsor_bottom_bio` text DEFAULT NULL AFTER `sponsor_bottom_website`,
  ADD COLUMN `sponsor_bottom_on_ticket` tinyint(1) NOT NULL DEFAULT 1 AFTER `sponsor_bottom_bio`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_003100_add_sponsor_details_to_events', 11);
