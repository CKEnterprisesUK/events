-- =============================================================================
-- 003_create_platform_settings.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `platform_settings` table — a single-row holder of
--   Platform-wide config. `global_fee_percent` is the default Platform fee %
--   applied to Companies that have no `company_fee_percent` override. Applied
--   on shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order; there is no SSH/web deploy route on production.
--
-- Covers tables:
--   * platform_settings    (Platform-wide config)          -- Requirements 20.5, 20.6
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_000900_create_platform_settings_table).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events platform_settings
--
-- Applying on prod:
--   Paste this file into phpMyAdmin, then apply 004_seed_platform_settings.sql
--   to insert the single default config row. The final INSERT below records the
--   platform_settings migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   003 (initial) — create platform_settings table.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `platform_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `platform_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `global_fee_percent` decimal(5,2) NOT NULL DEFAULT 5.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_000900_create_platform_settings_table', 3);
