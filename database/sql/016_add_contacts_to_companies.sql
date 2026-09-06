-- =============================================================================
-- 016_add_contacts_to_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds two nullable contact-address columns to the `companies` table:
--     * `support_email`       — the public-facing support contact shown to
--                               Customers.
--     * `gdpr_contact_email`  — the data-protection / GDPR contact for
--                               data-subject requests.
--   Both are Company settings managed by the Owner and form one of the four
--   steps in the initial account-setup checklist on the dashboard. Both are
--   NULL until the Owner completes onboarding.
--
--   This is an incremental ALTER applied AFTER 002_create_companies.sql: it
--   assumes the `companies` table already exists. Applied on shared cPanel
--   hosting by pasting this file into phpMyAdmin (Import / SQL tab) in filename
--   order.
--
-- Covers changes:
--   * companies.support_email       (nullable public support contact)
--   * companies.gdpr_contact_email  (nullable GDPR/data-protection contact)
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
--   (migration: 2024_01_01_000101_add_contacts_to_companies_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql. The final
--   INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   016 (initial) — add nullable `companies.support_email` and
--        `companies.gdpr_contact_email` contact columns (onboarding checklist).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  ADD COLUMN `support_email` varchar(254) DEFAULT NULL AFTER `terms_text`,
  ADD COLUMN `gdpr_contact_email` varchar(254) DEFAULT NULL AFTER `support_email`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_000101_add_contacts_to_companies_table', 4);
