-- ============================================================
-- AstrX migration: Data-retention / ephemerality console (core — module = '')
-- ============================================================
-- Admin page WORDING_ADMIN_RETENTION ('admin_retention', AdminRetentionController)
--   → child of the admin root; sets per-target age windows and shreds on demand.
-- No new table: retention windows live in `site_config` (retention_days_<key>);
-- the engine operates on existing tables (bot_trap_log, tipline, chat_report,
-- chat_message, chat_pm). Idempotent — safe to re-run.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_RETENTION', 1, 'admin_retention', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_RETENTION';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_RETENTION';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_RETENTION';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_RETENTION';

-- ── Admin navbar entry ("Data retention") ────────────────────────────────────
SET @ret_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_RETENTION' LIMIT 1);
SET @admin_navbar_id   := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id      := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_ret_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @ret_admin_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @ret_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_ret_admin_nav IS NULL;
SET @ret_admin_nav_id := COALESCE(@existing_ret_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @ret_admin_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_RETENTION', 1, 1, 0
 WHERE @ret_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @ret_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @ret_admin_nav_id, @ret_admin_page_id
 WHERE @ret_admin_page_id IS NOT NULL AND @ret_admin_nav_id IS NOT NULL;
