-- =============================================================================
-- 020_add_capacity_mode_to_ticket_types.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Introduces a per-ticket-type capacity mode on the `ticket_types` table so a
--   type can either carry its own capped ceiling (the existing behaviour) or
--   draw entirely from the Event's overall capacity as a shared pool.
--
--     * ticket_types.capacity_mode  — 'capped' (own ceiling) or 'shared_pool'
--       (governed solely by the Event overall capacity). NOT NULL, DEFAULT
--       'capped' so existing rows and future inserts keep current semantics.
--     * ticket_types.capacity       — relaxed to NULLABLE so shared-pool types
--       may omit a per-type ceiling. The column stays a SIGNED int(11) to match
--       008_create_ticket_types.sql; only nullability changes.
--
--   A backfill UPDATE then classifies existing rows: a type whose capacity
--   equalled its Event's non-null overall capacity becomes 'shared_pool';
--   every other row stays 'capped'.
--
--   This is an incremental ALTER applied AFTER 008_create_ticket_types.sql (and
--   006_create_events.sql, which the backfill joins against): it assumes the
--   `ticket_types` and `events` tables already exist. Applied on shared cPanel
--   hosting by pasting this file into phpMyAdmin (Import / SQL tab) in filename
--   order.
--
-- Covers changes:
--   * ticket_types.capacity_mode  (new NOT NULL DEFAULT 'capped' discriminator)
--   * ticket_types.capacity       (relaxed NOT NULL -> NULL, type unchanged)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `ticket_types` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent
--   with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events ticket_types
--
--   (migration: 2024_01_01_001900_add_capacity_mode_to_ticket_types)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 008_create_ticket_types.sql. The
--   final INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `ticket_types`
  ADD COLUMN `capacity_mode` varchar(20) NOT NULL DEFAULT 'capped' AFTER `capacity`,
  MODIFY COLUMN `capacity` int(11) DEFAULT NULL;

-- Classify existing rows: shared_pool iff the type's capacity equalled its
-- Event's non-null overall capacity, otherwise capped.
UPDATE `ticket_types` `tt`
  JOIN `events` `e` ON `e`.`id` = `tt`.`event_id`
  SET `tt`.`capacity_mode` = CASE
    WHEN `e`.`capacity` IS NOT NULL AND `tt`.`capacity` = `e`.`capacity` THEN 'shared_pool'
    ELSE 'capped'
  END;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001900_add_capacity_mode_to_ticket_types', 6);
