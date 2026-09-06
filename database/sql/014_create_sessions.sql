-- =============================================================================
-- 014_create_sessions.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `sessions` table required by the database session driver
--   (`SESSION_DRIVER=database`). Every web request runs through the
--   StartSession middleware, which reads/writes a row here keyed by session id;
--   without the table the storefront (and every `web` route) 500s with
--   "Base table or view not found: 1146 Table '<db>.sessions' doesn't exist".
--   Applied on shared cPanel hosting by pasting this file into phpMyAdmin
--   (Import / SQL tab) in filename order (after 005_create_users.sql, which
--   creates the referenced `users` table for the nullable `user_id`).
--
-- Covers tables:
--   * sessions   (database session store)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migrations via mysqldump of the migrated MySQL
--   schema (migration: 0001_01_01_000000_create_users_table, which ships the
--   framework `sessions` table alongside `users`/`password_reset_tokens`).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migrations:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events sessions
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 005_create_users.sql. No `migrations`
--   ledger row is inserted here: the `sessions` table belongs to the framework
--   `0001_01_01_000000_create_users_table` migration, whose ledger row is
--   already recorded by 005_create_users.sql.
--
-- Changelog:
--   014 (initial) — create sessions table (database session driver store).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
