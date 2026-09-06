-- =============================================================================
-- 029_create_audit_logs.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Append-only audit trail of security-, money-, access-, and privacy-
--   sensitive actions: who did what, to what, when, and from where. Read by
--   organisers (Owner/Admin) for their own Company at `/dashboard/activity` and
--   by Super_Admins cross-tenant at `/admin/audit`, where impersonated staff
--   actions are flagged.
--
--   The table is NOT tenant-scoped by the global company_id scope: system/
--   webhook events have no acting user (and may have no tenant), and Super_Admin
--   actions must be visible across Companies. `company_id` is a plain nullable
--   column and callers scope explicitly (as with `users`/`invitations`).
--
--   Columns:
--     * `company_id`           — tenant affected (NULL = platform/system). FK →
--                                companies ON DELETE SET NULL (keep trail on purge).
--     * `actor_user_id`        — who performed it (NULL = system/customer). FK →
--                                users ON DELETE SET NULL.
--     * `actor_label`          — snapshot of the actor (survives user deletion).
--     * `actor_type`           — user / super_admin / system / customer.
--     * `is_impersonated`      — Super_Admin acted while impersonating a Company.
--     * `impersonator_user_id` — the Super_Admin's real id when impersonated.
--     * `action`               — stable machine key, e.g. `order.refunded`.
--     * `auditable_type`/`_id` — polymorphic subject (order, event, ...).
--     * `summary`              — pre-rendered human sentence for the list view.
--     * `context`              — small PII-minimised JSON payload.
--     * `ip_address`           — request IP (IPv4/IPv6).
--     * `created_at`           — when it happened. NO `updated_at`: rows are
--                                immutable once written.
--
--   This is a CREATE applied AFTER 005_create_users.sql and 002_create_companies.sql
--   (it references both via foreign keys). Applied on shared cPanel hosting by
--   pasting this file into phpMyAdmin (Import / SQL tab) in filename order.
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration by migrating the MySQL schema and
--   dumping the `audit_logs` table via mysqldump. Regenerate this file whenever
--   the corresponding migration changes so the SQL stays consistent with it:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events audit_logs
--
--   (migration: 2024_01_01_002800_create_audit_logs_table)
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 028_add_agreed_to_terms_at_to_users.sql.
--   The final INSERT records the migration in the `migrations` ledger so the
--   app's migration state stays consistent if migrations are ever run against
--   this database later.
--
-- Changelog:
--   029 (initial) — create `audit_logs`.
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `actor_user_id` bigint(20) unsigned DEFAULT NULL,
  `actor_label` varchar(255) DEFAULT NULL,
  `actor_type` enum('user','super_admin','system','customer') NOT NULL DEFAULT 'user',
  `is_impersonated` tinyint(1) NOT NULL DEFAULT 0,
  `impersonator_user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `auditable_type` varchar(255) DEFAULT NULL,
  `auditable_id` bigint(20) unsigned DEFAULT NULL,
  `summary` varchar(500) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_company_id_created_at_index` (`company_id`,`created_at`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_impersonator_user_id_foreign` (`impersonator_user_id`),
  KEY `audit_logs_company_id_index` (`company_id`),
  KEY `audit_logs_actor_user_id_index` (`actor_user_id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_created_at_index` (`created_at`),
  CONSTRAINT `audit_logs_actor_user_id_foreign` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_impersonator_user_id_foreign` FOREIGN KEY (`impersonator_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2024_01_01_002800_create_audit_logs_table', 11);
