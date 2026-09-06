-- 022_drop_ticket_field_defs.sql
--
-- Remove the custom ticket-fields feature: drop the `ticket_field_defs` JSON
-- column from both `companies` and `events`. The feature added confusing,
-- never-valued fields to tickets and has been removed from the product surface.
--
-- Apply on shared cPanel hosting by pasting this file into phpMyAdmin
-- (Import / SQL tab) in filename order, after the create scripts. Idempotent-ish
-- on MariaDB 10.4+ / MySQL 8+ via IF EXISTS.

ALTER TABLE `companies` DROP COLUMN IF EXISTS `ticket_field_defs`;
ALTER TABLE `events` DROP COLUMN IF EXISTS `ticket_field_defs`;
