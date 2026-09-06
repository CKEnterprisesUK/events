-- =============================================================================
-- 015_create_cache_tables.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `cache` and `cache_locks` tables required by the database cache
--   store (`CACHE_STORE=database`). The tenant-resolution middleware
--   (App\Http\Middleware\ResolveTenant) memoises Company-by-slug lookups via
--   cache()->remember(), so every storefront request touches the `cache` table;
--   without it the `web` + `tenant` pipeline 500s with "Base table or view not
--   found: 1146 Table '<db>.cache' doesn't exist". `cache_locks` backs atomic
--   cache locks. Applied on shared cPanel hosting by pasting this file into
--   phpMyAdmin (Import / SQL tab) in filename order.
--
-- Covers tables:
--   * cache        (database cache store)
--   * cache_locks  (atomic lock support for the cache store)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migrations via mysqldump of the migrated MySQL
--   schema (migration: 0001_01_01_000001_create_cache_table).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migrations:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events cache cache_locks
--
-- Applying on prod:
--   Paste this file into phpMyAdmin. The final INSERT records the cache
--   migration in the `migrations` ledger so the app's migration state stays
--   consistent if migrations are ever run against this database later.
--
-- Changelog:
--   015 (initial) — create cache and cache_locks tables (database cache store).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('0001_01_01_000001_create_cache_table', 1);
