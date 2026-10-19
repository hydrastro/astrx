-- ============================================================
-- IMAGEBOARD DISCOVERY PAGES (overboard + search; hidden, link-reached)
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_BOARD_OVERBOARD', 1, 'board_overboard', 1, 1, 0, 0),   -- overboard: newest threads across all boards (unlisted; link-reached)
    ('WORDING_BOARD_SEARCH',    1, 'board_search',    1, 1, 0, 0);   -- post/thread search (no-JS GET form; unlisted; link-reached)

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_BOARD_OVERBOARD','WORDING_BOARD_SEARCH');

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_BOARD_OVERBOARD','WORDING_BOARD_SEARCH');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id IN ('WORDING_BOARD_OVERBOARD','WORDING_BOARD_SEARCH');
