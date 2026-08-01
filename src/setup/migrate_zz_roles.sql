-- ============================================================
-- AstrX migration: roles / sensitive-levers viewer (R14)
-- ============================================================
-- Registers WORDING_ADMIN_ROLES ('admin_roles', AdminRolesController) — a
-- read-only admin page under the admin root, with an admin navbar entry. No
-- new table (it reads the live Gate grants). Alpha admin pin + pin-independent
-- dedup guard (the R12-corrected pattern). Idempotent.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_ROLES', 1, 'admin_roles', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_ROLES';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_ROLES';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_ROLES';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_ROLES';

SET @roles_page_id   := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_ROLES' LIMIT 1);
SET @admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_roles_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @roles_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @roles_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_roles_nav IS NULL;
SET @roles_nav_id := COALESCE(@existing_roles_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @roles_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_ROLES', 1, 1, 0
 WHERE @roles_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @roles_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @roles_nav_id, @roles_page_id
 WHERE @roles_page_id IS NOT NULL AND @roles_nav_id IS NOT NULL;
