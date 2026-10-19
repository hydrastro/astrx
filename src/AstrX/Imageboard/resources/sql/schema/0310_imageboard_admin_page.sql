-- ============================================================
-- IMAGEBOARD ADMIN PAGE (global config + board overview) + admin navbar
-- The board overview is folded into the config page — one admin surface.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_CONFIG_IMAGEBOARD',1, 'admin_config_imageboard', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';
-- Child of the admin root so its template resolves under admin/.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';

-- Admin navbar entries (admin nav, last pin = the alpha-sorted group).
SET @admin_ib_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_ib_pin_id := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_ib_navbar_id
     ORDER BY sort_order DESC, id DESC LIMIT 1
);
-- WORDING_ADMIN_CONFIG_IMAGEBOARD
SET @ib_cfg_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD' LIMIT 1);
SET @existing_ib_cfg_nav := (
    SELECT ni.id FROM `navbar_internal` ni JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @ib_cfg_page_id AND e.pin_id = @admin_ib_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @ib_cfg_page_id IS NOT NULL AND @admin_ib_pin_id IS NOT NULL AND @existing_ib_cfg_nav IS NULL;
SET @ib_cfg_nav_id := COALESCE(@existing_ib_cfg_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @ib_cfg_nav_id, @admin_ib_pin_id, 1, 'WORDING_ADMIN_CONFIG_IMAGEBOARD', 1, 1, 0
 WHERE @ib_cfg_page_id IS NOT NULL AND @admin_ib_pin_id IS NOT NULL AND @ib_cfg_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @ib_cfg_nav_id, @ib_cfg_page_id
 WHERE @ib_cfg_page_id IS NOT NULL AND @ib_cfg_nav_id IS NOT NULL;
