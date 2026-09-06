-- =============================================================================
-- 030_create_reserved_slugs.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Company_Slug blocklist. Creates the `reserved_slugs` table holding slugs
--   that may never be claimed by a Company at self-signup or on a slug change.
--
--   Path-based tenancy routes every storefront at `/{company-slug}/...`, so a
--   slug that collides with a reserved top-level prefix (`login`, `admin`,
--   `dashboard`, `trust`, `webhooks`, ...) or an infrastructure path (`api`,
--   `assets`, `.well-known`, ...) would create an unreachable/confusing
--   storefront and could break silently if a future reserved prefix is added.
--   The table also holds brand/abuse words the Platform declines to hand out.
--
--   Enforced by App\Rules\CompanySlug. Managed by a Super_Admin on `/admin`.
--
--   Columns:
--     * `slug`      — the blocked value, lowercase, UNIQUE.
--     * `reason`    — optional operator note explaining why it is blocked.
--     * `is_system` — seeded technical/infra words the admin cannot delete.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `reserved_slugs` table via mysqldump. Regenerate this file
--   whenever the corresponding migration changes so the SQL stays consistent:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events reserved_slugs
--
--   (migration: 2024_01_01_002900_create_reserved_slugs_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 029_create_audit_logs.sql. The final
--   INSERTs record the migration in the `migrations` ledger and seed the
--   system reserved slugs so the blocklist is populated on a fresh deploy.
--
-- Changelog:
--   030 (initial) — create `reserved_slugs` + seed system defaults.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `reserved_slugs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reserved_slugs_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Seed the system reserved slugs (technical/infra reserved words). These mirror
-- App\Models\ReservedSlug::SYSTEM_DEFAULTS; keep the two in sync. `is_system`
-- rows cannot be deleted from the admin UI. Idempotent on re-run via IGNORE.
INSERT IGNORE INTO `reserved_slugs` (`slug`, `reason`, `is_system`, `created_at`, `updated_at`) VALUES
  ('admin', 'Reserved platform route', 1, NOW(), NOW()),
  ('login', 'Reserved platform route', 1, NOW(), NOW()),
  ('logout', 'Reserved platform route', 1, NOW(), NOW()),
  ('register', 'Reserved platform route', 1, NOW(), NOW()),
  ('dashboard', 'Reserved platform route', 1, NOW(), NOW()),
  ('invitations', 'Reserved platform route', 1, NOW(), NOW()),
  ('trust', 'Reserved platform route', 1, NOW(), NOW()),
  ('privacy', 'Reserved platform route', 1, NOW(), NOW()),
  ('webhooks', 'Reserved platform route', 1, NOW(), NOW()),
  ('forgot-password', 'Reserved platform route', 1, NOW(), NOW()),
  ('reset-password', 'Reserved platform route', 1, NOW(), NOW()),
  ('email', 'Reserved platform route', 1, NOW(), NOW()),
  ('verify-email', 'Reserved platform route', 1, NOW(), NOW()),
  ('api', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('assets', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('storage', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('build', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('css', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('js', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('fonts', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('images', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('img', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('.well-known', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('robots.txt', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('sitemap.xml', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('favicon.ico', 'Reserved infrastructure path', 1, NOW(), NOW()),
  ('www', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('mail', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('support', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('help', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('billing', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('account', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('settings', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('official', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('events', 'Reserved / impersonation risk', 1, NOW(), NOW()),
  ('ckenterprises', 'Reserved / impersonation risk', 1, NOW(), NOW());

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002900_create_reserved_slugs_table', 12);
