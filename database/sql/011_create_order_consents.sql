-- =============================================================================
-- 011_create_order_consents.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `order_consents` table — one row per consent selection the
--   Customer made at checkout (e.g. `terms`, `privacy`, `marketing`), stored on
--   the Order so the accepted/declined state is retained (Requirements 10.3,
--   10.4, 22.4). It is Company-owned (`company_id` references `companies`) and
--   belongs to an Order (`order_id` references `orders`), both cascading on
--   delete, and relies on the global tenant scope for isolation. `accepted`
--   records whether the consent was given; `captured_at` records when. Note
--   `captured_at` is emitted with a `current_timestamp()` default (and the
--   MySQL-implicit ON UPDATE current_timestamp() on the first TIMESTAMP column)
--   because it is a NOT NULL TIMESTAMP; the application sets it explicitly at
--   capture time. Applied on shared cPanel hosting by pasting this file into
--   phpMyAdmin (Import / SQL tab) in filename order (after
--   002_create_companies.sql and 009_create_orders.sql, which create the
--   referenced tables).
--
-- Covers tables:
--   * order_consents   (Company-owned consent capture)   -- Requirements 10.3, 10.4, 22.4
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_001500_create_order_consents_table). The
--   foreign keys to `companies` and `orders` are created inline by the
--   migration and are emitted here as part of the scoped dump. Regenerate this
--   file whenever the corresponding migration changes so the SQL stays
--   byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events order_consents
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 009_create_orders.sql. The final
--   INSERT records the order_consents migration in the `migrations` ledger so
--   the app's migration state stays consistent if migrations are ever run
--   against this database later.
--
-- Changelog:
--   011 (initial) — create order_consents table (Company-owned, order_id FK
--        cascading on delete, consent_key, accepted boolean, captured_at
--        timestamp).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `order_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `consent_key` varchar(255) NOT NULL,
  `accepted` tinyint(1) NOT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_consents_company_id_foreign` (`company_id`),
  KEY `order_consents_order_id_foreign` (`order_id`),
  CONSTRAINT `order_consents_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_consents_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001500_create_order_consents_table', 2);
