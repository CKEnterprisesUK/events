-- =============================================================================
-- 013_add_fulfilled_at_to_orders.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds the `fulfilled_at` timestamp to the `orders` table. When an Order is
--   confirmed (paid via the `checkout.session.completed` webhook, or
--   free-confirmed at checkout) the Platform fulfils it exactly once: it commits
--   the held capacity from reserved to sold, generates the QR, and enqueues the
--   ticket email (Requirements 14.1, 14.3). `fulfilled_at` is the idempotency
--   guard for that step — OrderFulfilmentService stamps it inside the same
--   locked transaction that commits the capacity, so a redelivered webhook, a
--   retried job, or a double confirmation never double-counts `sold_count` or
--   re-enqueues the email. The column is NULL until the Order is fulfilled.
--
--   This is an incremental ALTER applied AFTER 009_create_orders.sql: it assumes
--   the `orders` table already exists. Applied on shared cPanel hosting by
--   pasting this file into phpMyAdmin (Import / SQL tab) in filename order.
--
-- Covers changes:
--   * orders.fulfilled_at   (nullable fulfilment timestamp)   -- Requirements 14.1, 14.3
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `orders` table via mysqldump. Regenerate this file whenever the
--   corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events orders
--
--   (migration: 2024_01_01_001700_add_fulfilled_at_to_orders_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 009_create_orders.sql. The final
--   INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   013 (initial) — add nullable `orders.fulfilled_at` timestamp (fulfilment
--        idempotency guard: QR issued, capacity committed, ticket email enqueued).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `orders`
  ADD COLUMN `fulfilled_at` timestamp NULL DEFAULT NULL AFTER `scanned_by`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001700_add_fulfilled_at_to_orders_table', 3);
