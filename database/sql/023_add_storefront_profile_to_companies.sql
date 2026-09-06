-- =============================================================================
-- 023_add_storefront_profile_to_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds the public Storefront profile fields an Owner maintains to enrich
--   their public Company Storefront:
--     * `about_text`    — a free-text "about the company" description.
--     * `facebook_url`  — link to the organiser's Facebook page.
--     * `instagram_url` — link to the organiser's Instagram profile.
--     * `x_url`         — link to the organiser's X (Twitter) profile.
--     * `linkedin_url`  — link to the organiser's LinkedIn page.
--     * `terms_url`     — link to the organiser's own Terms & Conditions page.
--     * `privacy_url`   — link to the organiser's own Privacy Notice page.
--   The organisation `website` column already exists (see 017) and is reused by
--   the storefront, so it is not added here. All new columns are nullable — the
--   storefront omits anything the Owner leaves unset.
--
--   This is an incremental ALTER applied AFTER 002_create_companies.sql: it
--   assumes the `companies` table already exists. Applied on shared cPanel
--   hosting by pasting this file into phpMyAdmin (Import / SQL tab) in filename
--   order.
--
-- Covers changes:
--   * companies.about_text     (nullable "about the company" description)
--   * companies.facebook_url   (nullable Facebook link)
--   * companies.instagram_url  (nullable Instagram link)
--   * companies.x_url          (nullable X / Twitter link)
--   * companies.linkedin_url   (nullable LinkedIn link)
--   * companies.terms_url      (nullable organiser Terms & Conditions link)
--   * companies.privacy_url    (nullable organiser Privacy Notice link)
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
--   (migration: 2024_01_01_002200_add_storefront_profile_to_companies_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql. The final
--   INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   023 (initial) — add nullable storefront profile columns to `companies`:
--        about_text, facebook_url, instagram_url, x_url, linkedin_url,
--        terms_url, privacy_url.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  ADD COLUMN `about_text` text DEFAULT NULL AFTER `poster_path`,
  ADD COLUMN `facebook_url` varchar(255) DEFAULT NULL AFTER `about_text`,
  ADD COLUMN `instagram_url` varchar(255) DEFAULT NULL AFTER `facebook_url`,
  ADD COLUMN `x_url` varchar(255) DEFAULT NULL AFTER `instagram_url`,
  ADD COLUMN `linkedin_url` varchar(255) DEFAULT NULL AFTER `x_url`,
  ADD COLUMN `terms_url` varchar(255) DEFAULT NULL AFTER `linkedin_url`,
  ADD COLUMN `privacy_url` varchar(255) DEFAULT NULL AFTER `terms_url`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002200_add_storefront_profile_to_companies_table', 7);
