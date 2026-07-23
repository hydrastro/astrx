-- ============================================================
-- AstrX migration: chat profile expansion (le-chat parity, phase A)
-- ============================================================
-- Adds per-user profile fields to chat_settings (background colour, font
-- family, per-user sort direction, hide-chatters) and registers the chat Help
-- page. New migration file (never edit migrate_add_chat.sql — the setup runner
-- rejects an applied migration whose checksum changed). Idempotent.
-- ============================================================

-- ---- chat_settings: new per-user profile columns --------------------------
ALTER TABLE `chat_settings`
    ADD COLUMN IF NOT EXISTS `bg_color`      VARCHAR(16) NULL       AFTER `text_color`,
    ADD COLUMN IF NOT EXISTS `font_family`   VARCHAR(32) NULL       AFTER `bg_color`,
    ADD COLUMN IF NOT EXISTS `sort_dir`      TINYINT     NULL       AFTER `font_family`,
    ADD COLUMN IF NOT EXISTS `hide_chatters` TINYINT     NOT NULL DEFAULT 0 AFTER `sort_dir`;

-- ---- Chat Help page (templated, no navbar entry — reached via the chat toolbar)
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_CHAT_HELP', 1, 'chat_help', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_CHAT_HELP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_CHAT_HELP';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_CHAT_HELP';
