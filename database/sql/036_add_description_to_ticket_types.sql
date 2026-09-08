-- =============================================================================
-- 036_add_description_to_ticket_types.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   ALTER `ticket_types` to add an optional free-text `description` used by the
--   redesigned event-authoring accordion. Each ticket type may now carry a
--   short blurb shown while editing and (later) on the public event page.
--
--     * ticket_types.description  — nullable TEXT placed after `name`. NULL =
--       the ticket type has no description. Existing rows adopt NULL, so
--       behaviour is unchanged until an organiser enters a description.
--
--   The companion `unlimited` availability option needs NO schema change: the
--   `capacity_mode` column is already `varchar(20)` (see
--   020_add_capacity_mode_to_ticket_types.sql) and simply accepts the new
--   value, which is validated in application code (`Rule::in(TicketType::MODES)`).
--   That is recorded separately in 037_allow_unlimited_capacity_mode.sql so the
--   numbered trail stays complete.
--
-- Covers changes:
--   * ticket_types.description  (new nullable TEXT column)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration:
--   2025_01_01_000000_add_description_to_ticket_types).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events ticket_types
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 035_add_mail_transport_to_platform_settings.sql.
--   Requires `ticket_types` (008) to already exist. Existing rows adopt NULL,
--   so behaviour is unchanged. The final INSERT records the migration in the
--   `migrations` ledger so the app's migration state stays consistent if
--   migrations are ever run against this database later.
--
-- Changelog:
--   036 (initial) — add nullable `description` to ticket_types.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `ticket_types`
  ADD COLUMN `description` text DEFAULT NULL AFTER `name`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_01_000000_add_description_to_ticket_types', 14);
