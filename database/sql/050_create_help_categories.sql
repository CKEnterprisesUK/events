-- =============================================================================
-- 050_create_help_categories.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Backs the public Knowledge Base (and the in-dashboard Help portal): the
--   top-level sections that group related help articles (Getting started,
--   Events & tickets, Payments & payouts, …). This is the DB-backed
--   replacement for the hardcoded sections that used to live in
--   App\Support\HelpCentre.
--
--   Platform-wide content owned by Events by CK Enterprises UK, authored by a
--   Super_Admin, published at the public `/help` surface and linked from the
--   site footer and the app. NOT tenant-scoped (no `company_id`) — the same
--   guidance is shown to every Company. Keyed by a stable `slug` so the set of
--   sections is extensible from the admin without further schema changes.
--
--   Columns:
--     * `slug`         — stable public identifier (e.g. `getting-started`),
--                        used in the public URL `/help/{slug}`. UNIQUE.
--     * `title`        — human-readable heading shown on the hub and page.
--     * `summary`      — one-line description shown under the title. NULLable.
--     * `is_published` — only published sections appear publicly. DEFAULT 1.
--     * `sort_order`   — ordering on the Knowledge Base hub. DEFAULT 0.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `help_categories` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events help_categories
--
--   (migration: 2026_11_06_000000_create_help_categories_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 049 (the preceding schema file).
--   Apply 051_create_help_articles.sql immediately after this one (its FK
--   requires `help_categories` to already exist). Seed the default content via
--   the app's HelpContentSeeder, or author sections/articles in the admin.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   050 (initial) — create `help_categories`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `help_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `help_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_06_000000_create_help_categories_table', 15);
