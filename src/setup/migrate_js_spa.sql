-- ============================================================
-- AstrX migration: experimental JS SPA page (fix117)
-- Registers /<locale>/js/ backed by JsController.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_JS_APP', 1, 'js', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_JS_APP';

-- Search engines should NOT crawl the SPA — it's an experimental client
-- view of the same content that's already served the traditional way.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_JS_APP';
