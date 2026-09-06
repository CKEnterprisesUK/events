-- =============================================================================
-- 004_seed_platform_settings.sql
-- Event Ticketing Platform — versioned raw seed SQL for phpMyAdmin (no SSH on prod)
-- =============================================================================
--
-- Purpose:
--   Inserts the single default `platform_settings` row so a fresh prod database
--   has the required Platform-wide config. `global_fee_percent` defaults to the
--   Platform default (5.00) applied to Companies without a
--   `company_fee_percent` override. Apply after 003_create_platform_settings.sql
--   by pasting into phpMyAdmin (Import / SQL tab) in filename order.
--
-- Seeds:
--   * platform_settings  — one default config row       -- Requirements 20.5, 20.6
--
-- Provenance:
--   Mirrors the PlatformSettingSeeder (Database\Seeders\PlatformSettingSeeder),
--   which inserts one row with the default global_fee_percent
--   (PlatformSetting::DEFAULT_GLOBAL_FEE_PERCENT = '5.00'). Timestamps are set
--   to the apply time. Idempotent: reruns leave the existing row untouched.
--
-- Changelog:
--   004 (initial) — seed the single default platform_settings row.
-- =============================================================================

INSERT INTO `platform_settings` (`id`, `global_fee_percent`, `created_at`, `updated_at`)
SELECT 1, 5.00, UTC_TIMESTAMP(), UTC_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `platform_settings`);
