-- ============================================================
-- AstrX migration: content page visibility states + scheduling
-- (R8, wcms proposal A)
-- ============================================================
-- Adds richer visibility to the content module's pages, on top of the existing
-- `visible` published/draft toggle:
--   visibility  'public' | 'unlisted' | 'private'
--                 public   — listed + reachable by everyone
--                 unlisted — reachable only by direct URL (not in index/graph/sitemap)
--                 private  — reachable only by a logged-in viewer
--   publish_at  unix ts, NULL = live immediately
--   expire_at   unix ts, NULL = never expires
--
-- Runs AFTER migrate_zz_content_module.sql (…_module sorts before …_visibility,
-- so content_page already exists). Idempotent via ADD COLUMN IF NOT EXISTS.
-- ============================================================

ALTER TABLE `content_page`
    ADD COLUMN IF NOT EXISTS `visibility` VARCHAR(16)  NOT NULL DEFAULT 'public' AFTER `visible`,
    ADD COLUMN IF NOT EXISTS `publish_at` INT UNSIGNED NULL     DEFAULT NULL     AFTER `visibility`,
    ADD COLUMN IF NOT EXISTS `expire_at`  INT UNSIGNED NULL     DEFAULT NULL     AFTER `publish_at`;
