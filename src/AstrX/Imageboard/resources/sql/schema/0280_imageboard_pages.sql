-- ============================================================
-- IMAGEBOARD PAGES (dispatcher + token file-serve) + public navbar
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_BOARD',      1, 'board',      1, 1, 0, 0),   -- dispatcher: index/catalog/thread + posting
    ('WORDING_BOARD_FILE', 1, 'board_file', 0, 1, 0, 0),   -- raw image serve by token (no shell)
    ('WORDING_BOARD_MOD',  1, 'board_mod',  1, 1, 0, 0),   -- moderation surface (unlisted; link-reached, access gated by BOARD_MODERATE)
    ('WORDING_BOARD_FEED', 1, 'board_feed', 0, 1, 0, 0);   -- Atom feed (raw XML; unlisted; link-reached)

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_BOARD','WORDING_BOARD_FILE','WORDING_BOARD_MOD','WORDING_BOARD_FEED');

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_BOARD','WORDING_BOARD_FILE','WORDING_BOARD_MOD','WORDING_BOARD_FEED');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id IN ('WORDING_BOARD','WORDING_BOARD_FEED');
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_BOARD_FILE','WORDING_BOARD_MOD');

-- The board dispatcher is API-enabled: /api/board/<slug>[/thread/<id>|/catalog]
-- returns the same structured data (SHARED context) as a JSON envelope.
UPDATE `page` SET `api_enabled` = 1 WHERE `url_id` = 'WORDING_BOARD';

-- Public navbar entry for the board index (mirrors the chat entry).
SET @board_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_BOARD' LIMIT 1);
SET @board_pub_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @board_pub_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @board_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_board_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @board_page_id AND np.navbar_id = @board_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @board_page_id IS NOT NULL AND @board_pub_pin_id IS NOT NULL AND @existing_board_nav IS NULL;
SET @board_nav_id := COALESCE(@existing_board_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @board_nav_id, @board_pub_pin_id, 1, 'WORDING_BOARD', 1, 1, 0
 WHERE @board_page_id IS NOT NULL AND @board_pub_pin_id IS NOT NULL AND @board_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @board_nav_id, @board_page_id
 WHERE @board_page_id IS NOT NULL AND @board_nav_id IS NOT NULL;
