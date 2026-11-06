-- =============================================================================
-- 051_create_help_articles.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Backs the public Knowledge Base: the individual "how do I…?" / "why did…?"
--   articles that live under a `help_categories` section. Together with
--   `help_categories` this is the DB-backed replacement for the hardcoded
--   knowledge base that used to live in App\Support\HelpCentre.
--
--   Platform-wide content owned by Events by CK Enterprises UK, authored by a
--   Super_Admin and published at `/help/{category}/{article}`. NOT tenant
--   scoped. Keyed by a `slug` unique WITHIN its category so the public URL is
--   stable and readable.
--
--   Columns:
--     * `help_category_id` — owning section (FK, ON DELETE CASCADE).
--     * `slug`             — stable identifier, unique within the category.
--     * `title`            — article heading.
--     * `body`             — article content (Markdown, rendered safely on the
--                            public page). NULL/empty = not authored.
--     * `is_published`     — only published articles appear publicly. DEFAULT 1.
--     * `sort_order`       — ordering within the category. DEFAULT 0.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `help_articles` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events help_articles
--
--   (migration: 2026_11_06_000001_create_help_articles_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin AFTER 050_create_help_categories.sql — its
--   foreign key requires `help_categories` to already exist. Seed the default
--   content via the app's HelpContentSeeder, or author sections/articles in the
--   admin. The final INSERT records the migration in the `migrations` ledger so
--   the app's migration state stays consistent if migrations are ever run
--   against this database later.
--
-- Changelog:
--   051 (initial) — create `help_articles`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `help_articles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `help_category_id` bigint(20) unsigned NOT NULL,
  `slug` varchar(150) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` longtext DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `help_articles_help_category_id_slug_unique` (`help_category_id`,`slug`),
  CONSTRAINT `help_articles_help_category_id_foreign` FOREIGN KEY (`help_category_id`) REFERENCES `help_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_06_000001_create_help_articles_table', 15);
