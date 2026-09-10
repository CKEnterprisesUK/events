-- =============================================================================
-- 040_create_order_question_answers.sql
-- Event Ticketing Platform — versioned raw schema SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Creates the `order_question_answers` table — one row per answer the
--   Customer gave to a custom {@see event_questions} question at checkout,
--   stored on the Order so responses are retained for reporting. Modelled on
--   `order_consents`: Company-owned (`company_id` references `companies`),
--   belongs to an Order (`order_id` references `orders`, cascading on delete),
--   and points at the answered `event_question` (`event_question_id` references
--   `event_questions`, nulled on delete so the answer + its snapshot label
--   survive if the question is later removed).
--
--   `question_label` snapshots the question text at answer time. `answer` is
--   free text (a number is stored as its string form, a select stores the
--   chosen option) and is nullable so an optional, unanswered question can
--   still record an empty response.
--
-- Covers tables:
--   * order_question_answers   (Company-owned per-Order custom question answers)
--
-- Provenance (DO NOT hand-edit the DDL below):
--   Generated from the Laravel migration via mysqldump of the migrated MySQL
--   schema (migration: 2025_01_04_000000_create_order_question_answers_table).
--   Regenerate this file whenever the corresponding migration changes:
--
--     php artisan migrate --database=mysql
--     mysqldump -u root -h 127.0.0.1 --no-tablespaces --skip-add-locks \
--       --skip-comments --skip-set-charset --no-data \
--       events order_question_answers
--
-- Applying on prod:
--   Paste this file into phpMyAdmin after 039_create_event_questions.sql.
--   Requires `companies` (002), `orders` (009) and `event_questions` (039) to
--   already exist. The final INSERT records the migration in the `migrations`
--   ledger.
--
-- Changelog:
--   040 (initial) — create order_question_answers table (Company-owned,
--        order_id FK cascading on delete, event_question_id FK nulled on
--        delete, question_label snapshot, answer text, captured_at timestamp).
-- =============================================================================

/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `order_question_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_question_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `event_question_id` bigint(20) unsigned DEFAULT NULL,
  `question_label` varchar(255) NOT NULL,
  `answer` text DEFAULT NULL,
  `captured_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_question_answers_company_id_foreign` (`company_id`),
  KEY `order_question_answers_order_id_foreign` (`order_id`),
  KEY `order_question_answers_event_question_id_foreign` (`event_question_id`),
  CONSTRAINT `order_question_answers_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_question_answers_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `order_question_answers_event_question_id_foreign` FOREIGN KEY (`event_question_id`) REFERENCES `event_questions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;
/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Keep Laravel's migration ledger consistent when applied via phpMyAdmin.
INSERT INTO `migrations` (`migration`, `batch`) VALUES
  ('2025_01_04_000000_create_order_question_answers_table', 16);
