-- ============================================================
-- AstrX migration: captcha abuse policy columns (fix105)
-- Limits how often a captcha can be reloaded and adds a cooldown.
-- Safe to re-run.
-- ============================================================

-- regen_count : number of times this captcha has been reloaded.
--               Capped at CaptchaService::MAX_REGENS (default 5) — past that,
--               the regenerate call is a no-op and returns the existing image.
-- last_regen_at: timestamp of the most recent regeneration. Used by the
--               cooldown check (default 2s between regens for the same id).
ALTER TABLE `captcha`
    ADD COLUMN IF NOT EXISTS `regen_count`   INT       NOT NULL DEFAULT 0 AFTER `expires_at`,
    ADD COLUMN IF NOT EXISTS `last_regen_at` TIMESTAMP NULL              AFTER `regen_count`;
