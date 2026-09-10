-- =============================================================================
-- 039_create_event_questions.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `event_questions` table — the custom questions an organiser
--   configures for an Event, asked of the Customer at checkout. An Event may
--   carry up to three questions (the ceiling is enforced in application code,
--   not the schema).
--
--   Each question has a `type` — `free_text`, `select` (single choice, rendered
--   as radios), or `number` — a `label` shown to the Customer, an optional
--   `options` JSON array (used only by `select`), a `required` flag, and a
--   0-based `position` driving display + report column order. Questions are
--   Company-owned (`company_id` references `companies`) and belong to an Event
--   (`event_id` references `events`), both cascading on delete, and rely on the
--   global tenant scope for isolation.
--
-- Covers tables:
--   * event_questions   (Company-owned per-Event custom purchase questions)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2025_01_03_000000_create_event_questions_table).
--   Regenerate this file whenever the corresponding migration changes so the
--   SQL stays byte-consistent with the migration:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events event_questions
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 038_add_cancelled_at_to_events.sql.
--   Requires `companies` (002) and `events` (006) to already exist. The final
--   INSERT records the migration in the `migrations` ledger.
--
-- Changelog:
--   039 (initial) — create event_questions table (Company-owned, event_id FK
--        cascading on delete, type, label, options JSON, required boolean,
--        position).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `event_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `event_id` bigint(20) unsigned NOT NULL,
  `type` varchar(20) NOT NULL,
  `label` varchar(255) NOT NULL,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `required` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `event_questions_company_id_foreign` (`company_id`),
  KEY `event_questions_event_id_position_index` (`event_id`,`position`),
  CONSTRAINT `event_questions_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `event_questions_event_id_foreign` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_03_000000_create_event_questions_table', 16);
