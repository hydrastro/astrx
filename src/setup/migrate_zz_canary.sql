-- ============================================================
-- AstrX migration: Warrant canary (core feature — module = '')
-- ============================================================
-- Public page   WORDING_CANARY       ('canary',       CanaryController)
--   → HTML page, indexable, public navbar entry.
-- Admin editor  WORDING_ADMIN_CANARY ('admin_canary', AdminCanaryController)
--   → child of the admin root (inherits the ADMIN_ACCESS page guard + admin/
--     template dir), admin navbar entry.
-- The statement + attestation time live in the `site_config` KV table
-- (canary_* keys), so there is NO new table and NO signing key on the server —
-- the operator signs offline and pastes the block. Idempotent — safe to re-run.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_CANARY',       1, 'canary',       1, 1, 0, 0),
    ('WORDING_ADMIN_CANARY', 1, 'admin_canary', 1, 1, 0, 0);

-- Both pages belong to the independently-toggleable 'canary' module. Tagged here
-- (not in migrate_module_page_ownership_ext.sql) because that file sorts BEFORE
-- this one under glob(), so the pages don't exist yet when it runs. UPDATE (not
-- the INSERT's column list) so it also retrofits an already-installed row that
-- INSERT IGNORE leaves untouched. Only tags still-untagged rows — idempotent.
UPDATE `page` SET `module` = 'canary'
 WHERE `module` = '' AND `file_name` IN ('canary', 'admin_canary');

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_CANARY', 'WORDING_ADMIN_CANARY');
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_CANARY';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_CANARY', 'WORDING_ADMIN_CANARY');

-- Public canary IS indexable (a public trust document); the admin editor is not.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_CANARY';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_CANARY';

-- ── Public navbar entry ("Warrant canary") ───────────────────────────────────
SET @canary_page_id   := (SELECT id FROM `page` WHERE url_id = 'WORDING_CANARY' LIMIT 1);
SET @public_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @public_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @public_navbar_id ORDER BY sort_order ASC, id ASC LIMIT 1
);
-- Guard is pin-INDEPENDENT (match the entry by page within the navbar) so a
-- later consolidation move to another pin can't defeat the dedup on a re-run.
SET @existing_canary_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @canary_page_id AND np.navbar_id = @public_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @canary_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @existing_canary_nav IS NULL;
SET @canary_nav_id := COALESCE(@existing_canary_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @canary_nav_id, @public_pin_id, 1, 'WORDING_CANARY', 1, 1, 50
 WHERE @canary_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @canary_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @canary_nav_id, @canary_page_id
 WHERE @canary_page_id IS NOT NULL AND @canary_nav_id IS NOT NULL;

-- ── Admin navbar entry ("Warrant canary") ────────────────────────────────────
SET @canary_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_CANARY' LIMIT 1);
SET @admin_navbar_id      := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
-- Attach to the ALPHA GROUP pin (sort_mode = 0) — where every other admin tool
-- lives and where tables.sql's admin-nav consolidation KEEPS entries — not the
-- custom Dashboard pin (sort_mode = 1). This also stops a duplicate on replay:
-- the old "lowest sort_order" pick landed on the Dashboard pin, which the
-- consolidation then moved, defeating a pin-keyed dedup guard.
SET @admin_pin_id         := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
-- Pin-INDEPENDENT guard (match by page within the admin navbar).
SET @existing_canary_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @canary_admin_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @canary_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_canary_admin_nav IS NULL;
SET @canary_admin_nav_id := COALESCE(@existing_canary_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @canary_admin_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_CANARY', 1, 1, 0
 WHERE @canary_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @canary_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @canary_admin_nav_id, @canary_admin_page_id
 WHERE @canary_admin_page_id IS NOT NULL AND @canary_admin_nav_id IS NOT NULL;
