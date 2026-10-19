-- Canary: tag the module's pages and put its nav entries where the layout
-- expects them. Runs once (it is a migration); a no-op on an install that is
-- already laid out — a fresh one, or one that was upgraded in place.
--
-- It matters when the module's pages are created AFTER core reorganised the
-- navbars (migrate_zzz_navbar_layout.sql, migrate_zzz_admin_nav_pin_fix.sql):
-- the module's own SQL inserts its links into the FIRST pin of a navbar, which
-- after that reorganisation is the custom Home (public) or Dashboard (admin)
-- pin, not the alphabetical menu. That happens when the module is installed
-- on an existing site, and when it is restored after `tools/module.php purge`
-- (which forgets this module's migrations, so this one runs again).
--
-- Only entries sitting in that first pin are moved, so a link an admin placed
-- in another pin by hand stays where it is.

-- ── Page tree ────────────────────────────────────────────────────────────────
-- Every admin page sits under the admin root: that ancestry is what gates it
-- (ADMIN_ACCESS) and places its template under admin/. Core's closure repair
-- (migrate_zzzz_admin_closure_fix.sql) added the missing rows once; pages
-- created after it get theirs here.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE `module` = 'canary';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.`module` = 'canary' AND d.`file_name` LIKE 'admin\_%';

-- ── Public navbar: first (Home) pin → the alphabetical pin ──────────────────
SET @canary_pub := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @canary_pub_first := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @canary_pub ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @canary_pub_alpha := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @canary_pub AND sort_mode = 0 ORDER BY sort_order ASC, id ASC LIMIT 1
);
UPDATE `navbar_entry` e
  JOIN `navbar_internal` ni ON ni.id = e.id
  JOIN `page` p             ON p.id = ni.page_id
   SET e.pin_id = @canary_pub_alpha
 WHERE e.pin_id = @canary_pub_first
   AND @canary_pub_alpha IS NOT NULL AND @canary_pub_alpha <> @canary_pub_first
   AND p.`module` = 'canary';

-- ── Admin navbar: first (Dashboard) pin → the main menu pin ─────────────────
-- The main menu is the pin holding Users, else the alphabetical pin, else the
-- last pin — the rule migrate_zzz_admin_nav_pin_fix.sql uses.
SET @canary_adm := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @canary_adm_first := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @canary_adm ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @canary_adm_main := COALESCE(
    (SELECT e.pin_id FROM `navbar_entry` e
       JOIN `navbar_pin` np ON np.id = e.pin_id
      WHERE np.navbar_id = @canary_adm AND e.name = 'WORDING_ADMIN_USERS' LIMIT 1),
    (SELECT id FROM `navbar_pin` WHERE navbar_id = @canary_adm AND sort_mode = 0 ORDER BY sort_order ASC, id ASC LIMIT 1),
    (SELECT id FROM `navbar_pin` WHERE navbar_id = @canary_adm ORDER BY sort_order DESC, id DESC LIMIT 1)
);
UPDATE `navbar_entry` e
  JOIN `navbar_internal` ni ON ni.id = e.id
  JOIN `page` p             ON p.id = ni.page_id
   SET e.pin_id = @canary_adm_main
 WHERE e.pin_id = @canary_adm_first
   AND @canary_adm_main IS NOT NULL AND @canary_adm_main <> @canary_adm_first
   AND p.`module` = 'canary'
   AND p.`file_name` <> 'admin';
