-- =============================================================================
-- 038_add_cancelled_at_to_events.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds a nullable `cancelled_at` timestamp to the `events` table.
--
--   An Event that has taken confirmed bookings can no longer be deleted (its
--   booking records must survive so customers can be contacted and refunds
--   arranged). Instead the organiser cancels it: `cancelled_at` is stamped and
--   the Event is unpublished, dropping it from the public storefront while
--   retaining every Order. NULL = the Event is live/active as before.
--
--   This is an incremental ALTER applied AFTER 006_create_events.sql: it
--   assumes the `events` table already exists. Applied on shared hosting by
--   pasting this file into phpMyAdmin (Import / SQL tab) in filename order.
--
-- Covers changes:
--   * events.cancelled_at  (nullable cancellation timestamp; NULL = active)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Corresponds to the Laravel migration
--   2025_01_02_000000_add_cancelled_at_to_events. Regenerate this file whenever
--   that migration changes so the SQL stays consistent with it.
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 037. The final INSERT records the
--   migration in the `migrations` ledger exactly once.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `events`
  ADD COLUMN `cancelled_at` timestamp NULL DEFAULT NULL AFTER `is_published`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_02_000000_add_cancelled_at_to_events', 15);
