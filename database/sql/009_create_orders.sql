-- =============================================================================
-- 009_create_orders.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `orders` table — a Customer's checkout against an Event. It is
--   Company-owned (`company_id` references `companies`) and belongs to an Event
--   (`event_id` references `events`), both cascading on delete, and relies on
--   the global tenant scope for isolation. `order_reference` is UNIQUE across
--   the whole Platform so a scanned/looked-up reference is globally unambiguous
--   (Requirement 10.13). All money is held in integer minor currency units; the
--   fee breakdown (`ticket_subtotal_minor`, `booking_fee_minor`,
--   `application_fee_minor`, `order_total_minor`) and the `fee_handling_mode`
--   are SNAPSHOTTED onto the Order at creation so a later change to the
--   Company's fee mode never mutates an existing Order (Requirement 13.8). The
--   Order starts in `reserved` status with `reserved_until` set to now + 900s;
--   the scheduled release job expires holds whose window has elapsed
--   (Requirements 10.6, 10.7). The Stripe linkage columns and the scan columns
--   (`scanned_at`/`scanned_by`, the latter referencing `users` with ON DELETE
--   SET NULL) are nullable — they are populated by the payment (task 15),
--   webhook (task 16), and scan (task 19) slices. Applied on shared cPanel
--   hosting by pasting this file into phpMyAdmin (Import / SQL tab) in filename
--   order (after 002_create_companies.sql, 005_create_users.sql, and
--   006_create_events.sql, which create the referenced tables).
--
-- Covers tables:
--   * orders   (Company-owned Customer checkouts)   -- Requirements 10.1, 10.5, 10.6, 10.7, 10.13, 12.6, 13.8, 16.8
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_001300_create_orders_table). The foreign keys
--   to `companies`, `events`, and `users` are created inline by the migration
--   and are emitted here as part of the scoped dump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays
--   byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events orders
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 002_create_companies.sql,
--   005_create_users.sql, and 006_create_events.sql. The final INSERT records
--   the orders migration in the `migrations` ledger so the app's migration
--   state stays consistent if migrations are ever run against this database
--   later.
--
-- Changelog:
--   009 (initial) — create orders table (Company-owned, event_id FK,
--        order_reference UNIQUE across Platform, status enum defaulting to
--        `reserved`, money fields in integer minor units defaulting to 0,
--        fee_handling_mode snapshot, reserved_until 900s window, nullable
--        Stripe linkage + scan columns with scanned_by FK to users ON DELETE
--        SET NULL).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  `order_reference` varchar(255) NOT NULL,
  `customer_name` varchar(200) NOT NULL,
  `customer_email` varchar(254) NOT NULL,
  `status` enum('reserved','paid','free_confirmed','expired','cancelled','refunded','disputed','voided') NOT NULL DEFAULT 'reserved',
  `ticket_subtotal_minor` int(11) NOT NULL DEFAULT 0,
  `booking_fee_minor` int(11) NOT NULL DEFAULT 0,
  `application_fee_minor` int(11) NOT NULL DEFAULT 0,
  `order_total_minor` int(11) NOT NULL DEFAULT 0,
  `fee_handling_mode` enum('absorb','pass_on') NOT NULL,
  `reserved_until` timestamp NULL DEFAULT NULL,
  `stripe_session_id` varchar(255) DEFAULT NULL,
  `stripe_charge_id` varchar(255) DEFAULT NULL,
  `stripe_payment_intent_id` varchar(255) DEFAULT NULL,
  `scanned_at` timestamp NULL DEFAULT NULL,
  `scanned_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `orders_order_reference_unique` (`order_reference`),
  KEY `orders_company_id_foreign` (`company_id`),
  KEY `orders_event_id_foreign` (`event_id`),
  KEY `orders_scanned_by_foreign` (`scanned_by`),
  CONSTRAINT `orders_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `orders_scanned_by_foreign` FOREIGN KEY (`scanned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001300_create_orders_table', 2);
