-- ============================================================
-- AstrX migration: Encrypted anonymous tip line (core — module = '')
-- ============================================================
-- Public page  WORDING_TIPLINE       ('tipline',       TiplineController)
--   → HTML form, indexable, public navbar entry. Seals each submission to the
--     operator's sealed-box public key and stores ONLY the ciphertext.
-- Admin editor WORDING_ADMIN_TIPLINE ('admin_tipline', AdminTiplineController)
--   → child of the admin root; sets the public key + reviews/shreds the queue.
--
-- The `tipline` table holds base64 sealed boxes and a timestamp — no plaintext,
-- no IP, no session, no user id: a tip is unlinkable and unreadable at rest. The
-- public key lives in `site_config` (tipline_pubkey); decryption is offline only
-- (tools/tipline.php). Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `tipline` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sealed` MEDIUMTEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_TIPLINE',       1, 'tipline',       1, 1, 0, 0),
    ('WORDING_ADMIN_TIPLINE', 1, 'admin_tipline', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_TIPLINE', 'WORDING_ADMIN_TIPLINE');
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_TIPLINE';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_TIPLINE', 'WORDING_ADMIN_TIPLINE');

-- Public tip-line page IS indexable (people should be able to find it); admin is not.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_TIPLINE';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_TIPLINE';

-- ── Public navbar entry ("Anonymous tip line") ───────────────────────────────
SET @tip_page_id      := (SELECT id FROM `page` WHERE url_id = 'WORDING_TIPLINE' LIMIT 1);
SET @public_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @public_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @public_navbar_id ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_tip_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @tip_page_id AND np.navbar_id = @public_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @tip_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @existing_tip_nav IS NULL;
SET @tip_nav_id := COALESCE(@existing_tip_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @tip_nav_id, @public_pin_id, 1, 'WORDING_TIPLINE', 1, 1, 60
 WHERE @tip_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @tip_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @tip_nav_id, @tip_page_id
 WHERE @tip_page_id IS NOT NULL AND @tip_nav_id IS NOT NULL;

-- ── Admin navbar entry ("Tip line") ──────────────────────────────────────────
SET @tip_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_TIPLINE' LIMIT 1);
SET @admin_navbar_id   := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id      := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_tip_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @tip_admin_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @tip_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_tip_admin_nav IS NULL;
SET @tip_admin_nav_id := COALESCE(@existing_tip_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @tip_admin_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_TIPLINE', 1, 1, 0
 WHERE @tip_admin_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @tip_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @tip_admin_nav_id, @tip_admin_page_id
 WHERE @tip_admin_page_id IS NOT NULL AND @tip_admin_nav_id IS NOT NULL;
