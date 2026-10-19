-- ============================================================
-- SITE-WIDE SEARCH PAGE (news + pages + comments + board posts) + public navbar
-- Slug WORDING_SEARCH ('search'); file_name 'site_search' resolves to
-- SiteSearchController via the reflection router. Public (gated by NEWS_VIEW).
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_SEARCH', 1, 'site_search', 1, 1, 0, 0);   -- global search (no-JS GET form)

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_SEARCH';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_SEARCH';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_SEARCH';

-- Public navbar entry for the search page (mirrors the board/chat entries).
SET @search_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_SEARCH' LIMIT 1);
SET @search_pub_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @search_pub_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @search_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_search_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @search_page_id AND np.navbar_id = @search_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @search_page_id IS NOT NULL AND @search_pub_pin_id IS NOT NULL AND @existing_search_nav IS NULL;
SET @search_nav_id := COALESCE(@existing_search_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @search_nav_id, @search_pub_pin_id, 1, 'WORDING_SEARCH', 1, 1, 0
 WHERE @search_page_id IS NOT NULL AND @search_pub_pin_id IS NOT NULL AND @search_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @search_nav_id, @search_page_id
 WHERE @search_page_id IS NOT NULL AND @search_nav_id IS NOT NULL;
