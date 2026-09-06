-- =============================================================================
-- 027_create_legal_documents.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Platform-level Trust & Legal Centre. Creates the `legal_documents` table
--   holding Platform-wide policies (Terms & Conditions, Privacy Notice, PCI DSS
--   statement, cookie policy, and any others) authored by a Super_Admin,
--   published at public `/trust` URLs and linked from the site footer.
--
--   These are Platform documents owned by Events by CK Enterprises UK, distinct
--   from the per-Company `terms_text`/`privacy_text` shown to customers at
--   checkout. Held as multiple rows keyed by a stable `slug` so the set of
--   policies is extensible without further schema changes.
--
--   Columns:
--     * `slug`         — stable public identifier (e.g. `terms`, `privacy`), UNIQUE.
--     * `title`        — human-readable heading shown on the page and footer.
--     * `body`         — document content (Markdown/plain text). NULL = not authored.
--     * `is_published` — only published documents appear on the public surface.
--     * `sort_order`   — ordering on the Trust & Legal Centre hub page.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `legal_documents` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events legal_documents
--
--   (migration: 2024_01_01_002600_create_legal_documents_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 026_add_ticket_design_to_events.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   027 (initial) — create `legal_documents`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `legal_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(100) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` longtext DEFAULT NULL,
  `is_published` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `legal_documents_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002600_create_legal_documents_table', 11);
