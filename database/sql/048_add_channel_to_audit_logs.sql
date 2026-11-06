-- =============================================================================
-- 048_add_channel_to_audit_logs.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Record which surface produced an audit row, so the activity trail makes
--   clear whether an action was taken in the native iOS scanner app or on the
--   web dashboard (and distinguishes both from server-side/system events).
--   Adds to `audit_logs`:
--     * ADD `channel` — enum('app','web','system'), nullable. The origin surface
--       of the action: `app` (iOS scanner app, a Sanctum-authenticated `/api/*`
--       request), `web` (web dashboard, a session-authenticated request) or
--       `system` (a server-side event with no request surface, e.g. a webhook
--       or queue job). NULL for rows written before this column existed
--       (origin unknown). Indexed so the Activity / Audit surfaces can filter
--       by it.
--
--   This is an incremental ALTER applied AFTER 029_create_audit_logs.sql: it
--   assumes the `audit_logs` table already exists with an `actor_type` column.
--   Applied on shared cPanel hosting by pasting this file into phpMyAdmin
--   (Import / SQL tab) in filename order.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   diffing the `audit_logs` table via mysqldump. Regenerate this file whenever
--   the corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events audit_logs
--
--   (migration: 2026_11_05_000001_add_channel_to_audit_logs_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 047_add_data_retention_to_companies.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   048 (initial) — add nullable `channel` to `audit_logs`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `audit_logs`
  ADD COLUMN `channel` enum('app','web','system') DEFAULT NULL AFTER `actor_type`,
  ADD KEY `audit_logs_channel_index` (`channel`);

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2026_11_05_000001_add_channel_to_audit_logs_table', 14);
