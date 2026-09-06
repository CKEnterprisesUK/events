-- =============================================================================
-- 026_add_ticket_design_to_events.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Per-Event printed-ticket design. Adds the columns that drive the
--   downloadable A4 ticket PDF's look and copy:
--     * ADD `ticket_instructions` — free-text entry instructions printed on the
--       ticket (e.g. "Bring this e-ticket with you"). NULL = omit.
--     * ADD `sponsor_top_path`    — optional landscape sponsor banner image
--       shown across the TOP of the ticket. NULL = none.
--     * ADD `sponsor_bottom_path` — optional landscape sponsor banner image
--       shown across the BOTTOM of the ticket; also shown on the public
--       storefront/event page. NULL = none.
--
--   This is an incremental ALTER applied AFTER 019_add_poster_to_events.sql: it
--   assumes the `events` table already has the `poster_path` column. Applied on
--   shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order.
--
-- Covers changes:
--   * events.ticket_instructions  (nullable free-text ticket instructions)
--   * events.sponsor_top_path     (nullable top sponsor banner image path)
--   * events.sponsor_bottom_path  (nullable bottom sponsor banner image path)
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
--   (migration: 2024_01_01_002500_add_ticket_design_to_events)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 025_replace_legal_urls_with_privacy_text_on_companies.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   026 (initial) — add nullable `ticket_instructions`, `sponsor_top_path`,
--                   `sponsor_bottom_path` to `events`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `events`
  ADD COLUMN `ticket_instructions` text DEFAULT NULL AFTER `poster_path`,
  ADD COLUMN `sponsor_top_path` varchar(255) DEFAULT NULL AFTER `ticket_instructions`,
  ADD COLUMN `sponsor_bottom_path` varchar(255) DEFAULT NULL AFTER `sponsor_top_path`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002500_add_ticket_design_to_events', 10);
