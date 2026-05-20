-- ============================================================
-- AstrX migration: iframe-reloadable captcha pages (fix111)
-- Re-delivery of fix104's migrate_captcha_iframe.sql, but without the
-- api_enabled column reference — that column only exists after
-- migrate_api.sql has run, and the original migration would silently
-- fail on a DB that hadn't seen the API migration yet.
--
-- This version uses only columns present in the canonical schema in
-- src/setup/tables.sql, so it works regardless of which other
-- migrations have or haven't been applied.
--
-- Safe to re-run.
-- ============================================================

-- 1. The two page rows. Default api_enabled=0 is fine if the column exists;
--    if it doesn't, the column is simply not referenced.
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_CAPTCHA_IMAGE', 1, 'captcha_image', 0, 1, 0, 0),
    ('WORDING_CAPTCHA_FRAME', 1, 'captcha_frame', 0, 1, 0, 0);

-- 2. Closure self-references (used by the routing layer for ancestor lookups).
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- 3. Page meta — both hidden from search engines via page_robots.
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- ============================================================
-- VERIFICATION (run separately to check state):
--
-- SELECT id, url_id, file_name, template, controller, hidden
--   FROM `page`
--  WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');
--
-- Expected output: 2 rows, both with template=0, controller=1, hidden=1.
-- If you get 0 rows, this migration didn't succeed — check the schema
-- of the `page` table and re-run.
-- ============================================================
