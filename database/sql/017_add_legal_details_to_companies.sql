-- =============================================================================
-- 017_add_legal_details_to_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Captures the legal/registration details a Company must provide so the
--   Platform can identify and (where applicable) verify the organisation behind
--   a tenant — a legal-team requirement gathered at signup and maintained
--   thereafter on the Company branding/settings surface. Adds the following
--   columns to `companies`:
--     * legal_name        — registered/legal organisation name (required at signup)
--     * trading_name       — public-facing name, when different
--     * organisation_type  — company / charity / cic / sole_trader / club / other
--     * company_number     — Companies House number, where applicable
--     * charity_number     — Charity Commission number, where applicable
--     * website            — optional, useful for verification
--     * email              — main organisation email (required at signup)
--     * phone              — recommended main contact number
--     * address_line_1     — registered/business address (required at signup)
--     * address_line_2     — optional second address line
--     * city               — required at signup
--     * postcode           — required at signup
--     * country            — ISO 3166-1 alpha-2, defaults to 'GB'
--
--   Also widens the `status` enum from active/suspended to add `pending`
--   (awaiting verification) and `closed` (deactivated), keeping the default
--   `active`. The suspension gates key only off `suspended`, so the new values
--   are available for the verification workflow without changing signup/login
--   behaviour.
--
--   Every new column is nullable so this ALTER is backfill-free against existing
--   rows; signup enforces the required subset at the application layer. This is
--   an incremental ALTER applied AFTER 002_create_companies.sql (and after
--   016_add_contacts_to_companies.sql): it assumes the `companies` table already
--   exists. Applied on shared cPanel hosting by pasting this file into
--   phpMyAdmin (Import / SQL tab) in filename order.
--
-- Covers changes:
--   * companies.status widened enum('pending','active','suspended','closed')
--   * companies.legal_name / trading_name / organisation_type
--   * companies.company_number / charity_number / website
--   * companies.email / phone
--   * companies.address_line_1 / address_line_2 / city / postcode / country
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
--   (migration: 2024_01_01_000102_add_legal_details_to_companies_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 016_add_contacts_to_companies.sql. The
--   final INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   017 (initial) — widen `companies.status` enum (add pending/closed) and add
--        the legal/registration + address columns captured at signup and
--        managed on the branding/settings surface.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  MODIFY COLUMN `status` enum('pending','active','suspended','closed') NOT NULL DEFAULT 'active',
  ADD COLUMN `legal_name` varchar(255) DEFAULT NULL AFTER `name`,
  ADD COLUMN `trading_name` varchar(255) DEFAULT NULL AFTER `legal_name`,
  ADD COLUMN `organisation_type` enum('company','charity','cic','sole_trader','club','other') DEFAULT NULL AFTER `trading_name`,
  ADD COLUMN `company_number` varchar(50) DEFAULT NULL AFTER `organisation_type`,
  ADD COLUMN `charity_number` varchar(50) DEFAULT NULL AFTER `company_number`,
  ADD COLUMN `website` varchar(255) DEFAULT NULL AFTER `charity_number`,
  ADD COLUMN `email` varchar(254) DEFAULT NULL AFTER `website`,
  ADD COLUMN `phone` varchar(50) DEFAULT NULL AFTER `email`,
  ADD COLUMN `address_line_1` varchar(255) DEFAULT NULL AFTER `phone`,
  ADD COLUMN `address_line_2` varchar(255) DEFAULT NULL AFTER `address_line_1`,
  ADD COLUMN `city` varchar(255) DEFAULT NULL AFTER `address_line_2`,
  ADD COLUMN `postcode` varchar(20) DEFAULT NULL AFTER `city`,
  ADD COLUMN `country` char(2) NOT NULL DEFAULT 'GB' AFTER `postcode`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_000102_add_legal_details_to_companies_table', 5);
