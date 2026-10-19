-- ============================================================
-- AstrX migration: per-module page ownership (modularization Phase 2)
-- ============================================================
-- Adds `page.module` so every page declares which optional module owns it, and
-- rebuilds `resolved_page` to expose it. Core then gates a page at runtime when
-- its module is disabled in Modules.config.php (ModuleRegistry / ModulePageGuard)
-- and NavbarHandler drops nav entries that point at a disabled module's pages —
-- so turning a module off makes both its pages (404 → themed error) and its
-- navbar links disappear, with no broken links left behind.
--
-- module = '' means "core / always-on" (the default). Only the optional modules
-- wired to the registry are tagged. Runs after tables.sql via tools/install.php,
-- so it fixes both fresh and existing installs. Idempotent — safe to re-run.
-- ============================================================

-- 1. Ownership column (MariaDB supports IF NOT EXISTS on ADD COLUMN).
ALTER TABLE `page` ADD COLUMN IF NOT EXISTS `module` VARCHAR(32) NOT NULL DEFAULT '';

-- 2. Tag the optional modules' pages by file_name. Only tag rows still untagged
--    so an operator's manual re-assignment is never clobbered on re-run.
UPDATE `page` SET `module` = 'imageboard'
 WHERE `module` = ''
   AND (`file_name` LIKE 'board%' OR `file_name` = 'admin_config_imageboard');

UPDATE `page` SET `module` = 'chat'
 WHERE `module` = ''
   AND (`file_name` LIKE 'chat%' OR `file_name` IN ('admin_config_chat', 'admin_chat_filters'));

UPDATE `page` SET `module` = 'bottrap'
 WHERE `module` = ''
   AND `file_name` IN ('bot_trap', 'admin_trap');

-- 3. Rebuild resolved_page to carry `module` (mirrors the canonical view + module).
DROP VIEW IF EXISTS `resolved_page`;
CREATE VIEW `resolved_page` AS
SELECT p.id,
       p.url_id,
       p.i18n,
       p.file_name,
       p.template,
       p.controller,
       p.hidden,
       p.comments,
       p.api_enabled,
       p.module,
       COALESCE(pr.`index`, 1) AS `index`,
       COALESCE(pr.follow, 1) AS follow,
       COALESCE(pm.title, '') AS title,
       COALESCE(pm.description, '') AS description,
       COALESCE(t.file_name, '') AS template_file_name
FROM `page` p
         LEFT JOIN `page_robots`   pr ON pr.page_id = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id = p.id
         LEFT JOIN `page_template` pt ON pt.page_id = p.id
         LEFT JOIN `template`      t  ON t.id       = pt.template_id;
