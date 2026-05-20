-- ============================================================
-- AstrX migration: expose the global theme selector in admin nav
-- ============================================================
-- The theme manager page already exists on upgraded installs, but older
-- migrations created the page without adding it to the admin navbar.

SET @theme_page_id := (
    SELECT id FROM `page`
    WHERE url_id = 'WORDING_ADMIN_THEMES'
    LIMIT 1
);

SET @admin_navbar_id := (
    SELECT id FROM `navbar`
    WHERE name = 'admin'
    LIMIT 1
);

SET @admin_pin_id := (
    SELECT id FROM `navbar_pin`
    WHERE navbar_id = @admin_navbar_id
    ORDER BY sort_order DESC, id DESC
    LIMIT 1
);

SET @existing_theme_nav_entry_id := (
    SELECT ni.id
      FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @theme_page_id
       AND e.pin_id   = @admin_pin_id
     LIMIT 1
);

INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @theme_page_id IS NOT NULL
   AND @admin_pin_id   IS NOT NULL
   AND @existing_theme_nav_entry_id IS NULL;

SET @theme_nav_entry_id := COALESCE(@existing_theme_nav_entry_id, LAST_INSERT_ID());

INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @theme_nav_entry_id, @admin_pin_id, 1, 'WORDING_ADMIN_THEMES', 1, 1, 0
 WHERE @theme_page_id IS NOT NULL
   AND @admin_pin_id   IS NOT NULL
   AND @theme_nav_entry_id IS NOT NULL;

INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @theme_nav_entry_id, @theme_page_id
 WHERE @theme_page_id IS NOT NULL
   AND @theme_nav_entry_id IS NOT NULL;
