-- ============================================================
-- AstrX migration: chat parity phases C/D/E
-- ============================================================
-- Adds the per-user "incognito" flag to chat_settings (hide from the roster).
-- The phase C/D/E CONFIG keys (announce_join_leave, image_embed, entry_password,
-- chat_enabled, disabled_message) live in Chat.config.php, not the DB.
-- New migration file (never edit an applied one). Idempotent.
--
-- NOTE: no `AFTER <col>` clause. Migrations run in alphabetical filename order,
-- and this file sorts BEFORE migrate_chat_profile.sql — so the columns that
-- file adds (e.g. hide_chatters) may not exist yet. Column position is
-- cosmetic; the app reads columns by name, so we just append.
-- ============================================================

ALTER TABLE `chat_settings`
    ADD COLUMN IF NOT EXISTS `incognito` TINYINT NOT NULL DEFAULT 0;
