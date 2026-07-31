-- ============================================================
-- AstrX migration: XML sitemap page (R8, wcms proposal D)
-- ============================================================
-- Registers WORDING_SITEMAP → /<locale>/sitemap.xml, served by SitemapController
-- (template=0, controller=1 — raw XML, like the Atom feed). Core page
-- (module=''), no navbar entry (a crawler endpoint). Mirrors the WORDING_FEED
-- registration. Idempotent.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_SITEMAP', 1, 'sitemap', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_SITEMAP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_SITEMAP';

-- The sitemap document itself is not indexed.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_SITEMAP';
