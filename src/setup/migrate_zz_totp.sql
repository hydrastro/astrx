-- ============================================================
-- AstrX migration: TOTP two-factor auth (core — module = '')
-- ============================================================
-- Adds the per-user TOTP columns and two pages:
--   WORDING_TWOFA      ('twofa',     TwofaController)      — public second-factor
--       challenge at /login-2fa. hidden = 0 (a hidden page 404s for non-admins,
--       which would break the challenge for ordinary users); kept out of menus by
--       having NO navbar entry and out of search by robots index = 0.
--   WORDING_TWOFACTOR  ('twofactor', TwofactorController)  — logged-in user's 2FA
--       management at /settings-2fa; child of the user root, in the user navbar.
-- Secrets/recovery live on the `user` row. Idempotent — safe to re-run.
-- ============================================================

ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_secret`   VARCHAR(64) NULL;
ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_enabled`  TINYINT     NOT NULL DEFAULT 0;
ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_recovery` TEXT        NULL;
-- Dedicated brute-force counter for the /login-2fa challenge. It must live
-- OUTSIDE `login_attempts`, because a successful password step resets that to 0 —
-- so counting 2FA failures there let an attacker who holds the password reset the
-- throttle by re-submitting the password. This counter is only ever cleared on a
-- SUCCESSFUL second factor, so failures accumulate to the lockout regardless.
ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_fail_count` INT NOT NULL DEFAULT 0;
-- Highest TOTP time-step already accepted at the challenge. RFC 6238 §5.2: a code
-- must not be accepted twice — the login challenge rejects any step <= this, so a
-- code observed and replayed within its ~90s validity window is refused.
ALTER TABLE `user` ADD COLUMN IF NOT EXISTS `totp_last_step` BIGINT NOT NULL DEFAULT 0;

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_TWOFA',     1, 'twofa',     1, 1, 0, 0),
    ('WORDING_TWOFACTOR', 1, 'twofactor', 1, 1, 0, 0);

-- Closure: challenge is a standalone public page; management hangs under the
-- user root (grouping + User lang autoload), like profile/settings.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_TWOFA', 'WORDING_TWOFACTOR');
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_USER' AND d.url_id = 'WORDING_TWOFACTOR';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_TWOFA', 'WORDING_TWOFACTOR');

-- Neither page is indexed (a redirector challenge / a personal settings page).
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_TWOFA', 'WORDING_TWOFACTOR');

-- ── User navbar entry ("Two-factor authentication") ──────────────────────────
SET @tf_page_id     := (SELECT id FROM `page` WHERE url_id = 'WORDING_TWOFACTOR' LIMIT 1);
SET @user_navbar_id := (SELECT id FROM `navbar` WHERE name = 'user' LIMIT 1);
SET @user_pin_id    := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @user_navbar_id ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_tf_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @tf_page_id AND np.navbar_id = @user_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @tf_page_id IS NOT NULL AND @user_pin_id IS NOT NULL AND @existing_tf_nav IS NULL;
SET @tf_nav_id := COALESCE(@existing_tf_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @tf_nav_id, @user_pin_id, 1, 'WORDING_TWOFACTOR', 1, 1, 20
 WHERE @tf_page_id IS NOT NULL AND @user_pin_id IS NOT NULL AND @tf_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @tf_nav_id, @tf_page_id
 WHERE @tf_page_id IS NOT NULL AND @tf_nav_id IS NOT NULL;
