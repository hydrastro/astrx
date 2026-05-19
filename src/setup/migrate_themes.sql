-- ============================================================
-- AstrX migration: theme system (fix95)
-- Run ONCE on an existing database. Safe to re-run (uses IF NOT EXISTS).
-- ============================================================

-- 1. User table: per-user theme preference. NULL = use global theme.
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `theme` VARCHAR(64) NULL DEFAULT NULL
    AFTER `deletion_mode`;

-- 2. Register the admin themes page (idempotent — uses INSERT IGNORE).
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_THEMES', 1, 'admin_themes', 1, 1, 0, 0);

-- 3. Closure self-reference for the new page (no parent — top-level admin page).
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_THEMES';

-- 4. Page meta (title + description come from the lang file via WORDING_*).
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_THEMES';

-- 5. Robots: no-index, no-follow for admin pages.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_THEMES';
