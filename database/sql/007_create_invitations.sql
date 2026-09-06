-- =============================================================================
-- 007_create_invitations.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `invitations` table — a record that an Owner has invited a user
--   (by email) to join the Owner's Company with an assigned role. It is
--   Company-owned (`company_id` references `companies`, cascading on Company
--   delete) and relies on the global tenant scope for isolation. The `role` is
--   one of exactly {admin, accountant, scanner} — the Owner role is NOT
--   invitable (a Company's single Owner is only ever seeded or transferred).
--   `token` (UNIQUE) backs the opaque accept link; `accepted_at` records
--   acceptance (NULL while pending); `expires_at` bounds validity (nullable at
--   the DB level because MySQL strict mode rejects a NOT NULL TIMESTAMP without
--   a default — the application always sets it explicitly on create). Applied
--   on shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order (after 002_create_companies.sql, which creates the
--   referenced `companies` table).
--
-- Covers tables:
--   * invitations    (Company-owned invitations)   -- Requirements 4.1, 4.2, 4.6
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_001100_create_invitations_table). The foreign
--   key to `companies` is created inline by the migration and is emitted here as
--   part of the scoped dump. Regenerate this file whenever the corresponding
--   migration changes so the SQL stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events invitations
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql. The final
--   INSERT records the invitations migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   007 (initial) — create invitations table (Company-owned, role enum excludes
--        owner, unique token, accepted_at NULL while pending, expires_at).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `invitations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `invitations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` enum('admin','accountant','scanner') NOT NULL,
  `token` varchar(255) NOT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invitations_token_unique` (`token`),
  KEY `invitations_company_id_foreign` (`company_id`),
  CONSTRAINT `invitations_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001100_create_invitations_table', 2);
