-- ============================================================
-- AstrX migration: Invite-only registration (core feature)
-- ============================================================
-- One-time, admin-issued invite tokens. When require_invite is on (Invite
-- section of User.config.php) the register form demands a valid, unused code
-- and spends it atomically on sign-up.
--
--   invite   the tokens (code, note, created_by/at, used_by/at)
--
-- Page:  WORDING_ADMIN_INVITES ('admin-invites', file_name 'admin_invites')
--        → AdminInvitesController (reflection router: admin_invites → AdminInvites)
--
-- Invitations are a CORE, always-on feature — NOT a toggleable module — so the
-- page's `module` stays '' (never owned by a module, never hidden by one).
--
-- Named migrate_zz_* so it runs AFTER migrate_module_page_ownership*.sql (which
-- add page.module); the defensive ADD COLUMN below makes the ordering irrelevant.
-- Idempotent — safe to re-run.
-- ============================================================

CREATE TABLE IF NOT EXISTS `invite` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(64) NOT NULL,
  `note` VARCHAR(255) NOT NULL DEFAULT '',
  `created_by` BINARY(16) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `used_at` TIMESTAMP NULL DEFAULT NULL,
  `used_by` BINARY(16) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invite_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Page ─────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_INVITES', 1, 'admin_invites', 1, 1, 0, 0);   -- admin: mint / revoke invite codes

-- Closure: self-reference + the admin root as an ancestor. The admin ancestor
-- makes the template resolve to admin/admin_invites AND brings the page under
-- the ContentManager admin-access guard, like every other admin editor.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_INVITES';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id
  FROM `page` a
  JOIN `page` d ON d.url_id = 'WORDING_ADMIN_INVITES'
 WHERE a.url_id = 'WORDING_ADMIN';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_INVITES';

-- Admin page — never index/follow.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_INVITES';

-- ── Admin navbar entry ("Invitations") ───────────────────────────────────────
SET @invite_admin_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_INVITES' LIMIT 1);
SET @invite_admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
-- Attach to the ALPHA group pin (sort_mode = 0) — where every other admin tool
-- lives — so "Invitations" sorts in alphabetically instead of landing in the
-- custom Dashboard pin (the first pin). Fall back to the first pin only if no
-- alpha pin exists.
SET @invite_admin_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @invite_admin_navbar_id AND sort_mode = 0
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @invite_admin_pin_id := COALESCE(@invite_admin_pin_id, (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @invite_admin_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
));
-- Dedup across the WHOLE admin navbar (any pin), not just the target pin, so a row
-- already seeded into the old pin is reused (and left in place for the one-time
-- regroup SQL to move) rather than duplicated into the alpha pin on replay.
SET @existing_invite_admin_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @invite_admin_page_id AND np.navbar_id = @invite_admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @invite_admin_page_id IS NOT NULL AND @invite_admin_pin_id IS NOT NULL AND @existing_invite_admin_nav IS NULL;
SET @invite_admin_nav_id := COALESCE(@existing_invite_admin_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @invite_admin_nav_id, @invite_admin_pin_id, 1, 'WORDING_ADMIN_INVITES', 1, 1, 0
 WHERE @invite_admin_page_id IS NOT NULL AND @invite_admin_pin_id IS NOT NULL AND @invite_admin_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @invite_admin_nav_id, @invite_admin_page_id
 WHERE @invite_admin_page_id IS NOT NULL AND @invite_admin_nav_id IS NOT NULL;

-- ── Module ownership: none. Invitations are core/always-on, so `module` = ''. ─
-- Defensive column add (harmless if it already exists) keeps this migration
-- self-contained regardless of run order. We deliberately do NOT set `module`.
ALTER TABLE `page` ADD COLUMN IF NOT EXISTS `module` VARCHAR(32) NOT NULL DEFAULT '';
