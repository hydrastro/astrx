-- ============================================================
-- AstrX migration: enable API for SPA + safety-net view rebuild (fix120)
--
-- This re-delivers fix119's enablement and ALSO rebuilds the resolved_page
-- view in case the api_enabled column was missing from it (which would
-- cause $page->apiEnabled to always be NULL → /api/<slug> always 404).
--
-- Safe to re-run. Idempotent. No data loss.
-- ============================================================

-- 1. Ensure the column exists. NOTE: this is from migrate_api.sql (fix99);
--    we run it again here defensively in case the user has an older schema.
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0;

-- 2. Drop and recreate the resolved_page view so it includes api_enabled.
--    A view referencing a column that didn't exist when the view was
--    created will NOT pick up the column post-hoc — the view must be
--    rebuilt. DROP+CREATE is the simplest path.
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

-- 3. Flip api_enabled=1 on the pages the SPA needs.
UPDATE `page`
   SET `api_enabled` = 1
 WHERE `url_id` IN (
       'WORDING_MAIN',
       'WORDING_USER_HOME',
       'WORDING_PROFILE',
       'WORDING_LOGIN',
       'WORDING_REGISTER',
       'WORDING_RECOVER'
 );

-- ============================================================
-- VERIFICATION (paste these into a separate mysql session):
--
-- -- (a) Schema check: does page.api_enabled exist?
-- SHOW COLUMNS FROM page LIKE 'api_enabled';
-- -- expected: one row with Type=tinyint(4), Default=0
--
-- -- (b) View check: does resolved_page include api_enabled?
-- SHOW COLUMNS FROM resolved_page LIKE 'api_enabled';
-- -- expected: one row
--
-- -- (c) Data check: which pages have api_enabled=1?
-- SELECT url_id, api_enabled FROM page WHERE api_enabled = 1 ORDER BY url_id;
-- -- expected: 6 rows (MAIN, USER_HOME, PROFILE, LOGIN, REGISTER, RECOVER)
-- ============================================================
