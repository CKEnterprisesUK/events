-- =============================================================================
-- 002_create_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `companies` table — the tenant root of the Platform. Every
--   Company-owned row elsewhere carries `company_id` referencing this table,
--   and tenant isolation, suspension, fee handling, Stripe linkage, and
--   branding all hang off these columns. Applied on shared cPanel hosting by
--   pasting this file into phpMyAdmin (Import / SQL tab) in filename order;
--   there is no SSH/web deploy route on production.
--
-- Covers tables:
--   * companies    (tenant root)                          -- Requirements 1.1, 1.6, 2.x, 13.1, 13.2
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_000100_create_companies_table). Regenerate
--   this file whenever the corresponding migration changes so the SQL stays
--   byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events companies
--
-- Applying on prod:
--   Paste this file into phpMyAdmin. The final INSERT records the companies
--   migration in the `migrations` ledger so the app's migration state stays
--   consistent if migrations are ever run against this database later.
--
-- Changelog:
--   002 (initial) — create companies table.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `fee_handling_mode` enum('absorb','pass_on') NOT NULL DEFAULT 'absorb',
  `company_fee_percent` decimal(5,2) DEFAULT NULL,
  `stripe_account_id` varchar(255) DEFAULT NULL,
  `stripe_charges_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `currency` char(3) NOT NULL DEFAULT 'GBP',
  `primary_colour` varchar(7) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `terms_text` text DEFAULT NULL,
  `ticket_field_defs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ticket_field_defs`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `companies_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_000100_create_companies_table', 2);
