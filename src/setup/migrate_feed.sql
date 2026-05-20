-- ============================================================
-- AstrX migration: Atom feed page (fix115)
-- Registers the /<locale>/feed.xml endpoint backed by FeedController.
-- Idempotent.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_FEED', 1, 'feed', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_FEED';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_FEED';

-- Crawlers should know about the feed — index=1, follow=1.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_FEED';

-- Verify:
--   SELECT * FROM page WHERE url_id = 'WORDING_FEED';
