-- =============================================================================
-- 043_add_stripe_fee_to_orders.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   ALTER `orders` to add `stripe_fee_minor`: the ACTUAL card-processing fee
--   Stripe charged on the connected account for the Order, in integer minor
--   currency units, or NULL when it has not yet been captured (a free/unpaid
--   Order, or a paid Order whose balance transaction has not been retrieved
--   yet). This is distinct from `application_fee_minor` (the Platform's own fee
--   collected via `application_fee_amount`). Stripe's processing fee is deducted
--   inside the connected account and was previously invisible to the Platform,
--   so the "net to company" figure over-stated the real bank payout. The value
--   is read from the charge's Balance Transaction on the connected account after
--   payment confirmation (webhook), so it is the exact fee Stripe took — accurate
--   automatically if Stripe changes its pricing. Apply after 009_create_orders.sql
--   (and after 031, which added the preceding `refunded_total_minor` column).
--   (Truthful-payout feature)
--
-- Covers tables:
--   * orders   (add stripe_fee_minor)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2025_01_07_000000_add_stripe_fee_to_orders_table).
--   Regenerate this file whenever the corresponding migration changes so the SQL
--   stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events orders
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 031_add_refunded_total_to_orders.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   043 (initial) — add `stripe_fee_minor` INT NULL to `orders`, positioned
--        after `refunded_total_minor`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

ALTER TABLE `orders`
  ADD COLUMN `stripe_fee_minor` int(11) DEFAULT NULL AFTER `refunded_total_minor`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_07_000000_add_stripe_fee_to_orders_table', 3);
