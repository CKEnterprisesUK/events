-- =============================================================================
-- 035_add_mail_transport_to_platform_settings.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   ALTER `platform_settings` to add the Platform-wide outbound-mail transport
--   selector `mail_transport`. A Super_Admin flips this on the platform
--   Settings page to choose how the app delivers ALL outgoing mail (ticket,
--   support, and diagnostic test emails):
--
--     * `smtp`  — the default: Laravel's configured SMTP mailer (cPanel).
--     * `graph` — the Microsoft Graph API `sendMail` transport, sending from the
--                 organisation's own domain mailbox. Only takes effect once the
--                 Graph credentials (tenant/client id + secret + from mailbox)
--                 are present in the environment; until then the app falls back
--                 to SMTP even when `graph` is selected, so the toggle is always
--                 safe to leave set.
--
--   The Graph credentials themselves live in the environment/config, never in
--   the database — this column only records which transport is active.
--
-- Covers tables:
--   * platform_settings    (adds `mail_transport`)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration:
--   2024_01_01_003400_add_mail_transport_to_platform_settings_table).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events platform_settings
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 034_create_event_sponsors.sql.
--   Requires `platform_settings` (003) to already exist. Existing rows adopt the
--   `smtp` default, so behaviour is unchanged until a Super_Admin switches to
--   `graph` and the Graph environment credentials are configured. The final
--   INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   035 (initial) — add `mail_transport` to platform_settings.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `platform_settings`
  ADD COLUMN `mail_transport` enum('smtp','graph') NOT NULL DEFAULT 'smtp' AFTER `global_fee_percent`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_003400_add_mail_transport_to_platform_settings_table', 13);
