-- =============================================================================
-- 031_add_refunded_total_to_orders.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   ALTER `orders` to add `refunded_total_minor`: the cumulative amount refunded
--   back to the Customer for the Order, in integer minor currency units,
--   defaulting to 0. It lets a paid Order be PARTIALLY refunded one or more
--   times up to `order_total_minor` while the Order stays `paid` and its Tickets
--   stay valid; only once the cumulative refunds reach the full total does the
--   Order flip to the terminal `refunded` state (voiding Tickets and returning
--   capacity via the existing terminal path). A full refund in one step is just
--   the special case where the first partial refund equals the remaining
--   balance. Apply after 009_create_orders.sql. (Requirement 17.2)
--
-- Covers tables:
--   * orders   (add refunded_total_minor)   -- Requirement 17.2
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_003000_add_refunded_total_to_orders_table).
--   Regenerate this file whenever the corresponding migration changes so the SQL
--   stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events orders
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 009_create_orders.sql. The final
--   INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   031 (initial) — add `refunded_total_minor` INT NOT NULL DEFAULT 0 to
--        `orders`, positioned after `order_total_minor`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

ALTER TABLE `orders`
  ADD COLUMN `refunded_total_minor` int(11) NOT NULL DEFAULT 0 AFTER `order_total_minor`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_003000_add_refunded_total_to_orders_table', 3);
