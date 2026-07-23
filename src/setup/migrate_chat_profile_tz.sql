-- ============================================================
-- AstrX migration: per-user chat timezone + notes (profile enrichment)
-- ============================================================
-- Adds a per-user timezone (timestamps render in the viewer's zone) and a
-- personal notes scratchpad to chat_settings. New migration file (never edit an
-- applied one). Idempotent.
--
-- NOTE: no `AFTER <col>` clause — migrations run alphabetically and must not
-- depend on a column another migration adds. Column position is cosmetic.
-- ============================================================

ALTER TABLE `chat_settings`
    ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(48) NULL,
    ADD COLUMN IF NOT EXISTS `notes`    TEXT        NULL;
