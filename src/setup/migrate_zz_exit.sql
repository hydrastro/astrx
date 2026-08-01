-- ============================================================
-- AstrX migration: Off-site exit interstitial (core — module = '')
-- ============================================================
-- Public page WORDING_EXIT ('exit', ExitController) — template-rendered, no-JS.
--   Reached as /exit?to=<url>; it is a redirector, so it is NOT indexed and has
--   NO navbar entry. Content-page external links are routed here at render time
--   (ContentService + Markdown), so there is nothing to configure in the DB
--   beyond registering the page. Idempotent — safe to re-run.
-- ============================================================

-- hidden = 0: a hidden page is swapped to the 404/error page for every non-admin
-- (ContentManager), which would break the interstitial for ordinary visitors.
-- It is kept out of the menus simply by NOT adding a navbar entry, and out of
-- search by robots index = 0 below.
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_EXIT', 1, 'exit', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_EXIT';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_EXIT';

-- Redirector page — never indexed, never followed.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_EXIT';
