-- ---------- migrate_zz_repair_resolved_page.sql ----------
-- ============================================================
-- AstrX migration: final resolved_page shape repair.
--
-- Several older migrations rebuilt `resolved_page` with transitional
-- column names such as robots_index/meta_title. PageHandler expects the
-- canonical names below, especially `index` and `follow`.
--
-- This file is intentionally named migrate_zz_* so the setup wizard runs it
-- after older migrations that may recreate the view.
-- Safe to re-run.
-- ============================================================

ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0
    AFTER `comments`;

-- R10-01 (HIGH): the canonical view MUST expose `module`. The schema is re-run
-- in full on every setup re-entry (runSQL in public/setup.php, NOT a
-- checksum-tracked migration), so this final rebuild is the LAST word on the
-- view's shape. migrate_module_page_ownership.sql adds `module` to the view on
-- first install, but is checksum-skipped on every re-run — so without carrying
-- `module` HERE, a re-run (the normal upgrade path) drops `module` from the
-- view and every DISABLED module's public pages silently fail OPEN
-- (PageHandler reads no module column -> '' -> ModulePageGuard treats it as
-- core/always-on -> shown). Guarantee the column exists, then carry it below.
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `module` VARCHAR(32) NOT NULL DEFAULT ''
    AFTER `api_enabled`;

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
         LEFT JOIN `page_robots`   pr ON pr.page_id   = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id   = p.id
         LEFT JOIN `page_template` pt ON pt.page_id   = p.id
         LEFT JOIN `template`      t  ON t.id          = pt.template_id;

SELECT `id`, `url_id`, `i18n`, `file_name`, `template`, `controller`,
       `hidden`, `comments`, `api_enabled`, `module`, `index`, `follow`, `title`,
       `description`, `template_file_name`
  FROM `resolved_page`
 LIMIT 1;
