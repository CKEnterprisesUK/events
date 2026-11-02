-- =============================================================================
-- 047_add_data_retention_to_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Add the Company's customer-data retention settings. A Company chooses how
--   long it keeps a Customer's identifiable personal data (name/email on their
--   Orders) after that Customer's LAST order, before the daily
--   `customers:anonymise-expired` sweep automatically anonymises it. The
--   transactional records (order reference, money, fees, status) are always
--   retained — only the personal data is scrubbed. Adds to `companies`:
--     * ADD `retention_months` — retention window in months. Nullable: NULL
--       means "not yet chosen" and the app falls back to a 24-month default.
--     * ADD `retention_confirmed_at` — when the Owner explicitly confirmed the
--       retention choice (drives the onboarding checklist step). NULL until
--       confirmed, so a silent default never counts as a deliberate decision.
--
--   This is an incremental ALTER applied AFTER 002_create_companies.sql: it
--   assumes the `companies` table already exists. Applied on shared cPanel
--   hosting by pasting this file into phpMyAdmin (Import / SQL tab) in filename
--   order.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `companies` table via mysqldump. Regenerate this file whenever
--   the corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events companies
--
--   (migration: 2025_01_10_000000_add_data_retention_to_companies_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 046_add_stripe_requirements_to_companies.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   047 (initial) — add customer-data retention columns to `companies`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  ADD COLUMN `retention_months` smallint(5) unsigned DEFAULT NULL AFTER `gdpr_contact_email`,
  ADD COLUMN `retention_confirmed_at` timestamp NULL DEFAULT NULL AFTER `retention_months`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_10_000000_add_data_retention_to_companies_table', 2);
