-- =============================================================================
-- 008_create_ticket_types.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `ticket_types` table — a category of ticket within an Event,
--   carrying a name, price, capacity, and sale window. It is Company-owned
--   (`company_id` references `companies`) and belongs to an Event (`event_id`
--   references `events`), both cascading on delete, and relies on the global
--   tenant scope for isolation. Money is held in integer minor currency units
--   (`price_minor`, where 0 = free). `sold_count` tracks confirmed sales and
--   `reserved_count` tracks capacity held during active reservation windows;
--   remaining available = `capacity - sold_count - reserved_count`, enforced
--   (together with the overall Event capacity) by CapacityReservationService
--   using `SELECT ... FOR UPDATE` so concurrent checkouts serialize and never
--   oversell. `sale_starts_at`/`sale_ends_at` bound the sale window (nullable at
--   the DB level because MySQL strict mode rejects a NOT NULL TIMESTAMP without
--   a default — the application sets them per the ticket-type validation). The
--   default 0 for `sold_count`/`reserved_count` matches the model. Applied on
--   shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order (after 002_create_companies.sql and
--   006_create_events.sql, which create the referenced tables).
--
-- Covers tables:
--   * ticket_types   (Company-owned ticket categories)   -- Requirements 6.1, 6.3, 6.6, 6.7, 6.8, 6.9, 5.6, 10.6
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_001200_create_ticket_types_table). The
--   foreign keys to `companies` and `events` are created inline by the
--   migration and are emitted here as part of the scoped dump. Regenerate this
--   file whenever the corresponding migration changes so the SQL stays
--   byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events ticket_types
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql and
--   006_create_events.sql. The final INSERT records the ticket_types migration
--   in the `migrations` ledger so the app's migration state stays consistent if
--   migrations are ever run against this database later.
--
-- Changelog:
--   008 (initial) — create ticket_types table (Company-owned, event_id FK,
--        name VARCHAR(100), price_minor integer minor units (0 = free),
--        capacity, sold_count/reserved_count defaulting to 0, sale window).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ticket_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ticket_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `price_minor` int(11) NOT NULL,
  `capacity` int(11) NOT NULL,
  `sold_count` int(11) NOT NULL DEFAULT 0,
  `reserved_count` int(11) NOT NULL DEFAULT 0,
  `sale_starts_at` timestamp NULL DEFAULT NULL,
  `sale_ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ticket_types_company_id_foreign` (`company_id`),
  KEY `ticket_types_event_id_foreign` (`event_id`),
  CONSTRAINT `ticket_types_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_types_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001200_create_ticket_types_table', 2);
