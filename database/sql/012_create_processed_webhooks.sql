-- =============================================================================
-- 012_create_processed_webhooks.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `processed_webhooks` table — the webhook-idempotency ledger. A
--   row records a Stripe webhook event the Platform has already handled, keyed
--   by the Stripe event id. The UNIQUE index on `stripe_event_id` is the
--   idempotency guard: recording the id succeeds exactly once, so a redelivered
--   event is recognised as a duplicate and its heavy processing is skipped —
--   `checkout.session.completed` marks an Order paid at most once and creates no
--   additional charge on redelivery (Requirements 12.6, 12.7, 19.3). `type`
--   holds the Stripe event type (e.g. `checkout.session.completed`) and
--   `processed_at` records when the event was handled. Note `processed_at` is
--   emitted with a `current_timestamp()` default (and the MySQL-implicit ON
--   UPDATE current_timestamp() on the first TIMESTAMP column) because it is a
--   NOT NULL TIMESTAMP; the application sets it explicitly at processing time.
--
--   Unlike most tables here it is NOT Company-owned: Stripe posts every
--   account's webhooks to a single fixed Platform endpoint with no company
--   slug, so there is no tenant to scope the record to and it carries no
--   `company_id`. Applied on shared cPanel hosting by pasting this file into
--   phpMyAdmin (Import / SQL tab) in filename order; it has no foreign keys, so
--   it can be applied at any point after the `migrations` ledger exists
--   (001_create_queue_tables.sql).
--
-- Covers tables:
--   * processed_webhooks   (webhook idempotency ledger)   -- Requirements 12.6, 12.7, 19.3
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2024_01_01_001600_create_processed_webhooks_table).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events processed_webhooks
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 001_create_queue_tables.sql. The final
--   INSERT records the processed_webhooks migration in the `migrations` ledger
--   so the app's migration state stays consistent if migrations are ever run
--   against this database later.
--
-- Changelog:
--   012 (initial) — create processed_webhooks table (webhook idempotency ledger,
--        stripe_event_id UNIQUE, type, processed_at timestamp; not Company-owned).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `processed_webhooks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `processed_webhooks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stripe_event_id` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `processed_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `processed_webhooks_stripe_event_id_unique` (`stripe_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_001600_create_processed_webhooks_table', 2);
