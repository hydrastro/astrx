-- ============================================================
-- AstrX migration: bulletproof resolved_page view rebuild (fix122)
-- Resolves "Unknown column 'index' in 'SELECT'" from PageHandler.
--
-- Run with:
--   docker compose exec -T mariadb mysql -u user -ppassword content_manager \
--       < src/setup/migrate_fix_view.sql
--
-- This is the same as fix121 but with extra safety steps and explicit
-- error-on-failure. Idempotent. Safe to re-run.
-- ============================================================

-- 1. Make sure page.api_enabled exists (no-op if it already does).
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0;

-- 2. Drop the view unconditionally. We want a clean recreate, no merge
--    behavior. If the view doesn't exist, IF EXISTS prevents an error.
DROP VIEW IF EXISTS `resolved_page`;

-- 3. Recreate. Column names match PageHandler::getPage()'s SELECT exactly:
--    `id`, `url_id`, `i18n`, `file_name`, `template`, `controller`,
--    `hidden`, `comments`, `api_enabled`, `index`, `follow`, `title`,
--    `description`, `template_file_name`.
--    `index` and `follow` come from page_robots; `title`/`description`
--    from page_meta; `template_file_name` from the template table joined
--    via page_template.
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

-- 4. Inline verification (will fail loudly if anything is wrong).
--    The SELECT below uses the exact column list PageHandler expects.
--    If this returns rows (or even "Empty set"), the view is healthy.
--    If it errors out, the view is STILL broken and the rebuild above
--    didn't take.
SELECT `id`, `url_id`, `i18n`, `file_name`, `template`, `controller`,
       `hidden`, `comments`, `api_enabled`,
       `index`, `follow`,
       `title`, `description`,
       `template_file_name`
  FROM `resolved_page`
 LIMIT 1;
