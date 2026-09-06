-- =============================================================================
-- 005_create_users.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `users` table — every Company_User (Owner/Admin/Accountant/
--   Scanner) and CK Enterprises Super_Admin. `company_id` is NULL for
--   Super_Admins and references `companies` for Company_Users; `role` is one of
--   exactly four Company roles (NULL for Super_Admins); `last_activity_at`
--   backs the 30-minute idle timeout. The single-Owner invariant (one `owner`
--   per `company_id`) is enforced at the DB level via a generated column
--   (`owner_company_id`, = company_id only while role='owner', else NULL) with
--   a UNIQUE index over it — MySQL/MariaDB has no native partial unique index,
--   and UNIQUE ignores NULLs so only Owner rows compete. Applied on shared
--   cPanel hosting by pasting this file into phpMyAdmin (Import / SQL tab) in
--   filename order (after 002_create_companies.sql, which creates the
--   referenced `companies` table).
--
-- Covers tables:
--   * users    (Company_Users + Super_Admins)   -- Requirements 3.1, 3.2, 3.8, 3.11, 20.1
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migrations via mysqldump of the migrated MySQL
--   schema (migration: 0001_01_01_000000_create_users_table; the foreign key to
--   `companies` is added by 2024_01_01_000100_create_companies_table, replayed
--   here as an ALTER because a scoped `--no-data users` dump omits it).
--   Regenerate this file whenever the corresponding migrations change so the
--   SQL stays byte-consistent with the migrations:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events users
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql. The final
--   INSERT records the users migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   005 (initial) — create users table (four-role model, single-Owner invariant).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `role` enum('owner','admin','accountant','scanner') DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `last_activity_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `owner_company_id` bigint(20) unsigned GENERATED ALWAYS AS (case when `role` = 'owner' then `company_id` else NULL end) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_one_owner_per_company_unique` (`owner_company_id`),
  KEY `users_company_id_index` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Foreign key to companies (added by the companies migration; users.company_id
-- is NULL for Super_Admins and cascades on Company delete for Company_Users).
ALTER TABLE `users`
  ADD CONSTRAINT `users_company_id_foreign`
  FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
-- The users table ships in batch 1 (framework scaffold) but its DDL is applied
-- here after companies; the ledger row mirrors the migration filename.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('0001_01_01_000000_create_users_table', 1);
