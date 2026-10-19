-- ============================================================
-- AstrX migration: fix admin navbar pin for Content + Languages
-- ============================================================
-- The Content and Languages admin pages were seeded into the FIRST admin pin
-- (the custom-sorted "Dashboard" pin) instead of the main alphabetical admin
-- menu, so they showed up pinned to the top of the admin nav instead of sorting
-- into place. This forward migration moves them into the main menu pin.
--
-- The original migrations cannot be edited (the runner rejects an applied
-- migration whose checksum changed), so the correction ships as its own file.
-- Named migrate_zzz_* so it runs AFTER both migrate_zz_admin_language.sql and
-- migrate_zz_content_module.sql have created the entries. Idempotent.
-- ============================================================

SET @admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);

-- Main admin menu pin: prefer the pin that already holds a known core entry
-- (Users), else the alphabetical (sort_mode=0) pin, else the last pin.
SET @main_pin := (
    SELECT e.pin_id FROM `navbar_entry` e
      JOIN `navbar_pin` np ON np.id = e.pin_id
     WHERE np.navbar_id = @admin_navbar_id AND e.name = 'WORDING_ADMIN_USERS'
     LIMIT 1
);
SET @main_pin := COALESCE(
    @main_pin,
    (SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0 ORDER BY sort_order ASC, id ASC LIMIT 1),
    (SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id ORDER BY sort_order DESC, id DESC LIMIT 1)
);

-- Move the Content + Languages entries into that pin (no-op if already there).
UPDATE `navbar_entry` e
  JOIN `navbar_internal` ni ON ni.id = e.id
  JOIN `page` p            ON p.id = ni.page_id
  JOIN `navbar_pin` np     ON np.id = e.pin_id
   SET e.pin_id = @main_pin
 WHERE np.navbar_id = @admin_navbar_id
   AND p.file_name IN ('admin_content', 'admin_language')
   AND @main_pin IS NOT NULL
   AND e.pin_id <> @main_pin;
