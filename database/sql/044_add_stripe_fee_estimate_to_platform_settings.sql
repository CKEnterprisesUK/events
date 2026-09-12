-- =============================================================================
-- 044_add_stripe_fee_estimate_to_platform_settings.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   ALTER `platform_settings` to add a CONFIGURABLE estimate of Stripe's own
--   card-processing fee, so the pre-purchase calculator (landing page) and the
--   checkout preview can show buyers/organisers an approximate Stripe cut BEFORE
--   a payment settles. The exact fee is only known afterwards, from the charge's
--   balance transaction (orders.stripe_fee_minor, added in 043).
--
--     * stripe_fee_percent      DECIMAL(5,2) NOT NULL DEFAULT 1.50 — percent part
--     * stripe_fee_fixed_minor   INT NOT NULL DEFAULT 20           — fixed part (minor units)
--
--   These live in the DATABASE (editable at runtime by a Super_Admin), NOT in
--   .env or config, mirroring how the platform fee (`global_fee_percent`) is
--   configured — nothing about Stripe's pricing is hardcoded. Defaults reflect
--   Stripe's UK standard pricing at time of writing (1.5% + £0.20). This is an
--   ESTIMATE for display only; realised reporting uses the actual captured fee.
--   Apply after 003_create_platform_settings.sql. (Configurable-estimate feature)
--
-- Covers tables:
--   * platform_settings   (add stripe_fee_percent, stripe_fee_fixed_minor)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2025_01_08_000000_add_stripe_fee_estimate_to_platform_settings_table).
--   Regenerate this file whenever the corresponding migration changes so the SQL
--   stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events platform_settings
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 003_create_platform_settings.sql. The
--   final INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   044 (initial) — add `stripe_fee_percent` DECIMAL(5,2) NOT NULL DEFAULT 1.50
--        and `stripe_fee_fixed_minor` INT NOT NULL DEFAULT 20 to
--        `platform_settings`, positioned after `global_fee_percent`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

ALTER TABLE `platform_settings`
  ADD COLUMN `stripe_fee_percent` decimal(5,2) NOT NULL DEFAULT 1.50 AFTER `global_fee_percent`,
  ADD COLUMN `stripe_fee_fixed_minor` int(11) NOT NULL DEFAULT 20 AFTER `stripe_fee_percent`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_08_000000_add_stripe_fee_estimate_to_platform_settings_table', 3);
