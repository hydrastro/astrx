-- ============================================================
-- AstrX migration: Panic / lockdown control (core — module = '')
-- ============================================================
-- Admin page WORDING_ADMIN_PANIC ('admin_panic', AdminPanicController)
--   → child of the admin root; arms/disarms the site-wide lockdown and sets the
--     visitor message. No new table: state lives in `site_config`
--     (panic_active / panic_message). The enforcement gate is in ContentManager,
--     at the settling-GET / dispatch point. Idempotent — safe to re-run.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_PANIC', 1, 'admin_panic', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_PANIC';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_PANIC';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_PANIC';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_PANIC';

-- ── Admin navbar entry ("Panic / lockdown") ──────────────────────────────────
SET @panic_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_PANIC' LIMIT 1);
SET @admin_navbar_id     := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id        := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_panic_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @panic_admin_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @panic_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_panic_admin_nav IS NULL;
SET @panic_admin_nav_id := COALESCE(@existing_panic_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @panic_admin_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_PANIC', 1, 1, 0
 WHERE @panic_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @panic_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @panic_admin_nav_id, @panic_admin_page_id
 WHERE @panic_admin_page_id IS NOT NULL AND @panic_admin_nav_id IS NOT NULL;
