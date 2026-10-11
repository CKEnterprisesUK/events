-- =============================================================================
-- 046_add_stripe_requirements_to_companies.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Capture more of a connected Stripe account's onboarding/verification state
--   than the single `stripe_charges_enabled` flag, so the Payments page can tell
--   a Company exactly why an account is restricted. Adds to `companies`:
--     * ADD `stripe_payouts_enabled` — whether payouts are enabled (distinct
--       from charges; an account can take charges but have payouts held).
--     * ADD `stripe_details_submitted` — whether the account holder submitted
--       all required onboarding information (Stripe `details_submitted`).
--     * ADD `stripe_disabled_reason` — Stripe's machine-readable reason the
--       account is disabled/restricted, NULL when not disabled.
--     * ADD `stripe_requirements` — the account's outstanding requirements as
--       returned by Stripe (currently_due / past_due / pending_verification +
--       human-facing errors), stored verbatim as JSON.
--
--   This is an incremental ALTER applied AFTER 002_create_companies.sql: it
--   assumes the `companies` table already exists with a `stripe_charges_enabled`
--   column. Applied on shared cPanel hosting by pasting this file into
--   phpMyAdmin (Import / SQL tab) in filename order.
--
--   NOTE: This file backfills a previously-missing SQL counterpart for an
--   existing migration (the columns were already live via migrations); it is
--   numbered 046 so it applies after the other committed companies ALTERs. The
--   logical schema it produces is identical regardless of paste order.
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
--   (migration: 2024_01_01_000101_add_stripe_requirements_to_companies_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 045_add_disabled_at_to_users.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   046 (initial) — add Stripe requirements/verification columns to `companies`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `companies`
  ADD COLUMN `stripe_payouts_enabled` tinyint(1) NOT NULL DEFAULT 0 AFTER `stripe_charges_enabled`,
  ADD COLUMN `stripe_details_submitted` tinyint(1) NOT NULL DEFAULT 0 AFTER `stripe_payouts_enabled`,
  ADD COLUMN `stripe_disabled_reason` varchar(255) DEFAULT NULL AFTER `stripe_details_submitted`,
  ADD COLUMN `stripe_requirements` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`stripe_requirements`)) AFTER `stripe_disabled_reason`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_000101_add_stripe_requirements_to_companies_table', 2);
