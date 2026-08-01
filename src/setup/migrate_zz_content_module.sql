-- ============================================================
-- AstrX migration: Content module (W/wcms-inspired Markdown pages)
-- ============================================================
-- Author-written Markdown pages that interlink with [[wiki]] links, expose
-- backlinks, render a static-SVG page graph, and feed a broken-link checker.
--
--   content_page   the pages themselves (slug, title, Markdown body, visibility)
--   content_link   [[wiki]] edges: from_id → to_slug (to_id resolved, NULL = broken)
--
-- Pages:  WORDING_CONTENT       ('pages', file_name 'content')       → ContentController
--         WORDING_ADMIN_CONTENT ('admin-content', 'admin_content')   → AdminContentController
--
-- Named migrate_zz_* so it runs AFTER migrate_module_page_ownership*.sql (which
-- add page.module); the defensive ADD COLUMN below makes the ordering irrelevant.
-- Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `content_page` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`       VARCHAR(190) NOT NULL,
    `title`      VARCHAR(255) NOT NULL DEFAULT '',
    `body`       MEDIUMTEXT   NOT NULL,
    `visible`    TINYINT      NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_content_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `content_link` (
    `from_id` INT UNSIGNED NOT NULL,
    `to_slug` VARCHAR(190) NOT NULL,
    `to_id`   INT UNSIGNED NULL,
    PRIMARY KEY (`from_id`, `to_slug`),
    KEY `idx_content_link_to_slug` (`to_slug`),
    KEY `idx_content_link_to_id` (`to_id`),
    FOREIGN KEY (`from_id`) REFERENCES `content_page` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pages ────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_CONTENT',       1, 'content',       1, 1, 0, 0),   -- /pages index + /pages/<slug> view + ?view=graph
    ('WORDING_ADMIN_CONTENT', 1, 'admin_content', 1, 1, 0, 0);   -- admin editor + broken-link checker

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_CONTENT', 'WORDING_ADMIN_CONTENT');

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_CONTENT', 'WORDING_ADMIN_CONTENT');

-- Public index is indexable; the admin editor is not.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_CONTENT';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_CONTENT';

-- ── Public navbar entry ("Pages") — mirrors the search/board/chat entries ────
SET @content_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_CONTENT' LIMIT 1);
SET @content_pub_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @content_pub_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @content_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
-- Dedup across the WHOLE public navbar (any pin), not just the target pin, so the
-- later navbar-layout normalizer (which moves this entry into the alphabetical pin)
-- can't cause a replay to re-insert a duplicate in the first pin.
SET @existing_content_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @content_page_id AND np.navbar_id = @content_pub_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @content_page_id IS NOT NULL AND @content_pub_pin_id IS NOT NULL AND @existing_content_nav IS NULL;
SET @content_nav_id := COALESCE(@existing_content_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @content_nav_id, @content_pub_pin_id, 1, 'WORDING_CONTENT', 1, 1, 0
 WHERE @content_page_id IS NOT NULL AND @content_pub_pin_id IS NOT NULL AND @content_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @content_nav_id, @content_page_id
 WHERE @content_page_id IS NOT NULL AND @content_nav_id IS NOT NULL;

-- ── Admin navbar entry ("Content") ───────────────────────────────────────────
SET @content_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONTENT' LIMIT 1);
SET @content_admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @content_admin_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @content_admin_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
-- Dedup across the WHOLE admin navbar (any pin): migrate_zzz_admin_nav_pin_fix
-- later relocates this entry to the alphabetical pin, so a per-pin check would
-- miss the moved row on replay and re-insert a duplicate. Key on navbar_id.
SET @existing_content_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @content_admin_page_id AND np.navbar_id = @content_admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @content_admin_page_id IS NOT NULL AND @content_admin_pin_id IS NOT NULL AND @existing_content_admin_nav IS NULL;
SET @content_admin_nav_id := COALESCE(@existing_content_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @content_admin_nav_id, @content_admin_pin_id, 1, 'WORDING_ADMIN_CONTENT', 1, 1, 0
 WHERE @content_admin_page_id IS NOT NULL AND @content_admin_pin_id IS NOT NULL AND @content_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @content_admin_nav_id, @content_admin_page_id
 WHERE @content_admin_page_id IS NOT NULL AND @content_admin_nav_id IS NOT NULL;

-- ── Module ownership (defensive column add makes migration order irrelevant) ─
ALTER TABLE `page` ADD COLUMN IF NOT EXISTS `module` VARCHAR(32) NOT NULL DEFAULT '';
UPDATE `page` SET `module` = 'content'
 WHERE `module` = '' AND `file_name` IN ('content', 'admin_content');

-- ── Seed a welcome page so the module has something to show ───────────────────
INSERT IGNORE INTO `content_page` (`slug`, `title`, `body`, `visible`)
VALUES ('welcome', 'Welcome', '# Welcome\n\nThis is a **content page** written in Markdown.\n\nPages can link to each other with double brackets, e.g. [[welcome]] links back here, and [[about]] points at a page that does not exist yet (a *broken* link the checker will flag).\n\n- Edit these from the admin **Content** page.\n- See how they connect on the [page graph](?view=graph).', 1);
