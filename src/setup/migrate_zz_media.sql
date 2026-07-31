-- ============================================================
-- AstrX migration: Media module (general uploaded-media library)
-- ============================================================
-- A general-purpose uploaded-media manager (list / upload / rename / delete,
-- re-usable across content pages) using the SAME image validation + re-encode
-- (EXIF strip, decompression-bomb guard, MIME sniffing) the imageboard ships.
--
--   media   the stored, re-encoded files (name UNIQUE, sha256, dims, uploader)
--
-- Pages:  WORDING_ADMIN_MEDIA ('admin-media', file_name 'admin_media')
--             → AdminMediaController  (child of the admin root; template under admin/)
--         WORDING_MEDIA_FILE  ('media',       file_name 'media_file')
--             → MediaFileController   (raw byte endpoint, template=0, /media/<name>;
--               a machine endpoint — NOT under admin, so public content pages can
--               embed media without an admin session, and NO navbar entry)
--
-- Named migrate_zz_* so it runs AFTER migrate_module_page_ownership*.sql (which
-- add page.module); the defensive ADD COLUMN below makes the ordering irrelevant.
-- Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `media` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(190) NOT NULL,
  `orig_name` VARCHAR(255) NOT NULL DEFAULT '',
  `mime` VARCHAR(64) NOT NULL DEFAULT '',
  `ext` VARCHAR(8) NOT NULL DEFAULT '',
  `size` INT UNSIGNED NOT NULL DEFAULT 0,
  `sha256` CHAR(64) NOT NULL DEFAULT '',
  `width` INT UNSIGNED NOT NULL DEFAULT 0,
  `height` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by` BINARY(16) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_media_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Pages ────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_MEDIA', 1, 'admin_media', 1, 1, 0, 0),   -- admin manager (default shell + admin/admin_media.html)
    ('WORDING_MEDIA_FILE',  1, 'media_file',  0, 1, 0, 0);   -- raw byte endpoint (controller streams + exit())

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_ADMIN_MEDIA', 'WORDING_MEDIA_FILE');
-- admin_media is a child of the admin root so its template resolves under admin/
-- and it inherits the ADMIN_ACCESS page guard. media_file stays a ROOT page: it
-- is a public machine endpoint and must never require an admin session.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_MEDIA';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_ADMIN_MEDIA', 'WORDING_MEDIA_FILE');

-- Neither page is indexable: the admin manager is private, the file endpoint is
-- a machine route.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_ADMIN_MEDIA', 'WORDING_MEDIA_FILE');

-- ── Admin navbar entry ("Media") ─────────────────────────────────────────────
-- Copied verbatim from migrate_zz_content_module.sql's admin-navbar block.
SET @media_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_MEDIA' LIMIT 1);
SET @media_admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @media_admin_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @media_admin_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_media_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @media_admin_page_id AND e.pin_id = @media_admin_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @media_admin_page_id IS NOT NULL AND @media_admin_pin_id IS NOT NULL AND @existing_media_admin_nav IS NULL;
SET @media_admin_nav_id := COALESCE(@existing_media_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @media_admin_nav_id, @media_admin_pin_id, 1, 'WORDING_ADMIN_MEDIA', 1, 1, 0
 WHERE @media_admin_page_id IS NOT NULL AND @media_admin_pin_id IS NOT NULL AND @media_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @media_admin_nav_id, @media_admin_page_id
 WHERE @media_admin_page_id IS NOT NULL AND @media_admin_nav_id IS NOT NULL;

-- ── Module ownership (defensive column add makes migration order irrelevant) ─
ALTER TABLE `page` ADD COLUMN IF NOT EXISTS `module` VARCHAR(32) NOT NULL DEFAULT '';
UPDATE `page` SET `module` = 'media'
 WHERE `module` = '' AND `file_name` IN ('admin_media', 'media_file');
