-- ============================================================
-- AstrX migration: Signed downloads / release manifest (core — module = '')
-- ============================================================
-- Public page   WORDING_DOWNLOADS       ('downloads',       DownloadsController)
--   → HTML page, indexable, public navbar entry.
-- Admin editor  WORDING_ADMIN_DOWNLOADS ('admin_downloads', AdminDownloadsController)
--   → child of the admin root (inherits the ADMIN_ACCESS page guard + admin/
--     template dir), admin navbar entry.
-- The manifest text, ED25519 public key and detached signature live in the
-- `site_config` KV table (manifest_* keys) — NO new table and NO private key on
-- the server; the operator signs offline and pastes the block. The public page
-- verifies the signature server-side with ext-sodium. Idempotent — safe to re-run.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_DOWNLOADS',       1, 'downloads',       1, 1, 0, 0),
    ('WORDING_ADMIN_DOWNLOADS', 1, 'admin_downloads', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_DOWNLOADS', 'WORDING_ADMIN_DOWNLOADS');
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_DOWNLOADS';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_DOWNLOADS', 'WORDING_ADMIN_DOWNLOADS');

-- Public downloads page IS indexable (a public trust document); admin editor is not.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_DOWNLOADS';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_DOWNLOADS';

-- ── Public navbar entry ("Downloads") ────────────────────────────────────────
SET @dl_page_id       := (SELECT id FROM `page` WHERE url_id = 'WORDING_DOWNLOADS' LIMIT 1);
SET @public_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @public_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @public_navbar_id ORDER BY sort_order ASC, id ASC LIMIT 1
);
-- Guard is pin-INDEPENDENT (match the entry by page within the navbar) so a
-- later consolidation move to another pin can't defeat the dedup on a re-run.
SET @existing_dl_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @dl_page_id AND np.navbar_id = @public_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @dl_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @existing_dl_nav IS NULL;
SET @dl_nav_id := COALESCE(@existing_dl_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @dl_nav_id, @public_pin_id, 1, 'WORDING_DOWNLOADS', 1, 1, 55
 WHERE @dl_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @dl_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @dl_nav_id, @dl_page_id
 WHERE @dl_page_id IS NOT NULL AND @dl_nav_id IS NOT NULL;

-- ── Admin navbar entry ("Signed downloads") ──────────────────────────────────
SET @dl_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_DOWNLOADS' LIMIT 1);
SET @admin_navbar_id  := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
-- Attach to the ALPHA GROUP pin (sort_mode = 0) — where every other admin tool
-- lives and where tables.sql's admin-nav consolidation KEEPS entries.
SET @admin_pin_id     := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
-- Pin-INDEPENDENT guard (match by page within the admin navbar).
SET @existing_dl_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @dl_admin_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @dl_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_dl_admin_nav IS NULL;
SET @dl_admin_nav_id := COALESCE(@existing_dl_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @dl_admin_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_DOWNLOADS', 1, 1, 0
 WHERE @dl_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @dl_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @dl_admin_nav_id, @dl_admin_page_id
 WHERE @dl_admin_page_id IS NOT NULL AND @dl_admin_nav_id IS NOT NULL;
