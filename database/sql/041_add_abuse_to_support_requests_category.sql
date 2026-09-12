-- =============================================================================
-- 041_add_abuse_to_support_requests_category.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Widen the `support_requests.category` ENUM to include a new `abuse` value,
--   backing the public "Report this event" control at the bottom of every
--   Event page. A visitor's abuse/misuse report is filed as a support ticket
--   with `category = 'abuse'` so it lands in the Super_Admin support queue as a
--   distinct, triageable bucket rather than being lumped into `other`.
--
--   The value is inserted before `other` to keep `other` as the trailing
--   catch-all; the column default stays `other`.
--
-- Covers changes:
--   * support_requests.category  (ENUM value set gains 'abuse'; NOT NULL,
--                                 DEFAULT 'other' unchanged)
--
-- Provenance:
--   Generated from migration 2025_01_05_000000_add_abuse_to_support_requests_category.
--
-- Applying on prod:
--   Paste in phpMyAdmin (SQL tab) in filename order, after
--   040_create_order_question_answers.sql. Idempotent-safe to re-run: it simply
--   re-asserts the (already widened) enum value set. Requires the
--   `support_requests` table (033).
--
-- Changelog:
--   041 (initial) — add 'abuse' to support_requests.category enum.
-- =============================================================================

SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

ALTER TABLE `support_requests`
  MODIFY COLUMN `category`
    ENUM('account','events','orders','payments','billing','technical','abuse','other')
    NOT NULL DEFAULT 'other';

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_05_000000_add_abuse_to_support_requests_category', 12);
