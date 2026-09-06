-- =============================================================================
-- 010_create_tickets.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `tickets` table — a single purchased or claimed ticket within
--   an Order, recording its Ticket_Type. The Platform creates exactly one
--   Ticket per purchased/claimed ticket (Requirement 10.5). It is Company-owned
--   (`company_id` references `companies`) and belongs to an Order (`order_id`
--   references `orders`) and a Ticket_Type (`ticket_type_id` references
--   `ticket_types`), all cascading on delete, and relies on the global tenant
--   scope for isolation. `status` is `valid` on creation and flipped to
--   `voided` when the owning Order is cancelled/refunded in a later slice
--   (Requirement 17.3). Applied on shared cPanel hosting by pasting this file
--   into phpMyAdmin (Import / SQL tab) in filename order (after
--   002_create_companies.sql, 008_create_ticket_types.sql, and
--   009_create_orders.sql, which create the referenced tables).
--
-- Covers tables:
--   * tickets   (Company-owned per-ticket rows)   -- Requirements 10.5, 17.3
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_001400_create_tickets_table). The foreign
--   keys to `companies`, `orders`, and `ticket_types` are created inline by the
--   migration and are emitted here as part of the scoped dump. Regenerate this
--   file whenever the corresponding migration changes so the SQL stays
--   byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events tickets
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 008_create_ticket_types.sql and
--   009_create_orders.sql. The final INSERT records the tickets migration in
--   the `migrations` ledger so the app's migration state stays consistent if
--   migrations are ever run against this database later.
--
-- Changelog:
--   010 (initial) — create tickets table (Company-owned, order_id + ticket_type_id
--        FKs cascading on delete, status enum('valid','voided') defaulting to
--        `valid`).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `ticket_type_id` bigint(20) unsigned NOT NULL,
  `status` enum('valid','voided') NOT NULL DEFAULT 'valid',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `tickets_company_id_foreign` (`company_id`),
  KEY `tickets_order_id_foreign` (`order_id`),
  KEY `tickets_ticket_type_id_foreign` (`ticket_type_id`),
  CONSTRAINT `tickets_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `tickets_ticket_type_id_foreign` FOREIGN KEY (`ticket_type_id`) REFERENCES `ticket_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001400_create_tickets_table', 2);
