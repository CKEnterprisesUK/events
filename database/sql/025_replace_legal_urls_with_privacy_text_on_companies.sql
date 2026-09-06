-- =============================================================================
-- 025_replace_legal_urls_with_privacy_text_on_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Move the organiser's legal content in-app rather than linking out to it.
--   The Terms & Conditions and Privacy Notice are now authored within the app
--   (Settings) and shown to the customer on request at checkout, so the
--   external-link columns added in 023 are dropped:
--     * DROP `terms_url`   — organiser's own Terms & Conditions page link.
--     * DROP `privacy_url` — organiser's own Privacy Notice page link.
--   Terms already have an in-app `terms_text` column (added earlier); the
--   Privacy Notice gains a matching in-app column here:
--     * ADD  `privacy_text` — the Privacy Notice text shown at checkout.
--
--   This is an incremental ALTER applied AFTER 023_add_storefront_profile_to_companies.sql:
--   it assumes the `companies` table already has the 023 columns. Applied on
--   shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order.
--
-- Covers changes:
--   * companies.privacy_text  (nullable in-app Privacy Notice text)
--   * DROP companies.terms_url
--   * DROP companies.privacy_url
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
--   (migration: 2024_01_01_002400_replace_legal_urls_with_privacy_text_on_companies)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 023_add_storefront_profile_to_companies.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   025 (initial) — add nullable `privacy_text`; drop `terms_url`, `privacy_url`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  ADD COLUMN `privacy_text` text DEFAULT NULL AFTER `terms_text`;

ALTER TABLE `companies` DROP COLUMN IF EXISTS `terms_url`;
ALTER TABLE `companies` DROP COLUMN IF EXISTS `privacy_url`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002400_replace_legal_urls_with_privacy_text_on_companies', 9);
