-- ============================================================
-- AstrX migration: Language admin page (core, not a module)
-- ============================================================
-- A no-JavaScript translation editor: browse every translation domain, edit
-- each installed locale's strings side by side, and add a new language by
-- cloning an existing locale. Served by AdminLanguageController.
--
--   WORDING_ADMIN_LANGUAGE ('admin-language', file_name 'admin_language')
--
-- Core page — NOT module-owned, so page.module stays '' and it is never gated
-- by ModulePageGuard. Named migrate_zz_* so it runs after the ownership
-- migrations. Idempotent — safe to re-run.
-- ============================================================

-- ── Page ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_LANGUAGE', 1, 'admin_language', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_LANGUAGE';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_LANGUAGE';

-- Admin tool — never indexed.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_LANGUAGE';

-- ── Admin navbar entry ("Languages") — mirrors the other admin entries ───────
SET @lang_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_LANGUAGE' LIMIT 1);
SET @lang_admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @lang_admin_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @lang_admin_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_lang_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @lang_admin_page_id AND e.pin_id = @lang_admin_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @lang_admin_page_id IS NOT NULL AND @lang_admin_pin_id IS NOT NULL AND @existing_lang_admin_nav IS NULL;
SET @lang_admin_nav_id := COALESCE(@existing_lang_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @lang_admin_nav_id, @lang_admin_pin_id, 1, 'WORDING_ADMIN_LANGUAGE', 1, 1, 0
 WHERE @lang_admin_page_id IS NOT NULL AND @lang_admin_pin_id IS NOT NULL AND @lang_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @lang_admin_nav_id, @lang_admin_page_id
 WHERE @lang_admin_page_id IS NOT NULL AND @lang_admin_nav_id IS NOT NULL;
