-- =============================================================================
-- 043_add_mfa_to_users.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Adds optional, per-user TOTP two-factor authentication (RFC 6238) to
--   `users`. MFA is opt-in: a user enables it from their profile, scans a QR
--   into an authenticator app, and confirms with a code. Adds to `users`:
--     * ADD `two_factor_secret` — the Base32 TOTP shared secret, stored
--       ENCRYPTED (Laravel `encrypted` cast). NULL until the user starts
--       enrolment. `text` because the ciphertext is far longer than the raw
--       ~32-char secret.
--     * ADD `two_factor_recovery_codes` — a JSON array of one-time recovery
--       codes, stored ENCRYPTED. NULL until enrolment. Lets a user who has lost
--       their authenticator device still complete the login challenge.
--     * ADD `two_factor_confirmed_at` — when the user verified their first code
--       and MFA became active. NULL while a secret exists but is unconfirmed
--       (enrolment started but not finished) and NULL when MFA is off. Only a
--       user with a non-NULL value here is challenged at login.
--     * ADD `mfa_prompt_dismissed_at` — when the user chose "don't remind me
--       again" on the post-login MFA recommendation nudge. NULL means keep
--       showing the nudge at each login until they enable MFA or dismiss it.
--
--   This is an incremental ALTER applied AFTER 005_create_users.sql: it assumes
--   the `users` table already exists with a `remember_token` column. Applied on
--   shared cPanel hosting by pasting this file into phpMyAdmin (Import / SQL
--   tab) in filename order.
--
-- Provenance:
--   Hand-authored ALTER (no framework migration counterpart yet). If a Laravel
--   migration is later added for these columns, regenerate this file from a
--   mysqldump diff of `users` so the SQL stays byte-consistent with it, matching
--   the workflow documented in 005_create_users.sql / 028_*.
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 042_create_error_reports.sql. The
--   final INSERT records the migration in the `migrations` ledger so the app's
--   migration state stays consistent if migrations are ever run against this
--   database later.
--
-- Changelog:
--   043 (initial) — add optional TOTP MFA columns + nudge-dismissal to `users`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

ALTER TABLE `users`
  ADD COLUMN `two_factor_secret` text DEFAULT NULL AFTER `remember_token`,
  ADD COLUMN `two_factor_recovery_codes` text DEFAULT NULL AFTER `two_factor_secret`,
  ADD COLUMN `two_factor_confirmed_at` timestamp NULL DEFAULT NULL AFTER `two_factor_recovery_codes`,
  ADD COLUMN `mfa_prompt_dismissed_at` timestamp NULL DEFAULT NULL AFTER `two_factor_confirmed_at`;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
-- Mirrors the Laravel migration filename so migration state stays consistent
-- if migrations are ever run against this database later.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_08_000000_add_mfa_to_users_table', 12);
