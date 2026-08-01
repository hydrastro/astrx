-- ============================================================
-- AstrX migration: onion mirror / anti-phishing pages (R13)
-- ============================================================
-- Public page  WORDING_MIRRORS       ('mirrors',       MirrorsController)      — indexable, public navbar entry
-- Admin editor WORDING_ADMIN_MIRRORS ('admin_mirrors', AdminMirrorsController) — child of admin root, admin navbar entry
-- Data (canonical onion, mirror list, signed statement) lives in the site_config
-- KV (onion_* keys); the Onion-Location header is emitted by ContentManager. No
-- new table. Alpha admin pin + pin-independent dedup guards. Idempotent.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_MIRRORS',       1, 'mirrors',       1, 1, 0, 0),
    ('WORDING_ADMIN_MIRRORS', 1, 'admin_mirrors', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_MIRRORS', 'WORDING_ADMIN_MIRRORS');
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_MIRRORS';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_MIRRORS', 'WORDING_ADMIN_MIRRORS');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_MIRRORS';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_MIRRORS';

-- ── Public navbar entry ──────────────────────────────────────────────────────
SET @mir_page_id      := (SELECT id FROM `page` WHERE url_id = 'WORDING_MIRRORS' LIMIT 1);
SET @public_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @public_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @public_navbar_id ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_mir_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @mir_page_id AND np.navbar_id = @public_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @mir_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @existing_mir_nav IS NULL;
SET @mir_nav_id := COALESCE(@existing_mir_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @mir_nav_id, @public_pin_id, 1, 'WORDING_MIRRORS', 1, 1, 60
 WHERE @mir_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @mir_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @mir_nav_id, @mir_page_id WHERE @mir_page_id IS NOT NULL AND @mir_nav_id IS NOT NULL;

-- ── Admin navbar entry ───────────────────────────────────────────────────────
SET @mir_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_MIRRORS' LIMIT 1);
SET @admin_navbar_id   := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id      := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_mir_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @mir_admin_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @mir_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_mir_admin_nav IS NULL;
SET @mir_admin_nav_id := COALESCE(@existing_mir_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @mir_admin_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_MIRRORS', 1, 1, 0
 WHERE @mir_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @mir_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @mir_admin_nav_id, @mir_admin_page_id
 WHERE @mir_admin_page_id IS NOT NULL AND @mir_admin_nav_id IS NOT NULL;
