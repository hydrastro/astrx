-- Webmail base schema: its trusted-sender table, its two pages (the mailbox
-- under the user area, the IMAP config editor under admin) and their nav
-- entries. Applied right after the core base on every installer run, so it is
-- idempotent: CREATE TABLE IF NOT EXISTS, INSERT IGNORE, and nav entries that
-- are only created when the page has none yet. Pages are found by url_id, never
-- by a literal id (they moved out of the core base, which numbers its own).

-- ============================================================
-- WEBMAIL
-- ============================================================

CREATE TABLE IF NOT EXISTS `webmail_trusted_sender`
(
    `user_id`      BINARY(16)   NOT NULL,
    `sender_email` VARCHAR(320) NOT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `sender_email`),
    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);

-- ----------------------------------------------------------
-- Pages
-- ----------------------------------------------------------

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_WEBMAIL',              1, 'webmail',              1, 1, 0, 0),
    ('WORDING_ADMIN_CONFIG_WEBMAIL', 1, 'admin_config_webmail', 1, 1, 0, 0);

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_WEBMAIL', 'WORDING_ADMIN_CONFIG_WEBMAIL');

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, 'Webmail', 'Read and send emails from your mailbox.'
  FROM `page` WHERE url_id = 'WORDING_WEBMAIL';
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, 'Config — Webmail / IMAP', 'Edit IMAP and webmail configuration.'
  FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_WEBMAIL';

-- Self-closures, then: webmail sits under the user area, its config editor
-- under the admin root (so its template resolves under admin/).
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_WEBMAIL', 'WORDING_ADMIN_CONFIG_WEBMAIL');
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_USER' AND d.url_id = 'WORDING_WEBMAIL';
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_CONFIG_WEBMAIL';

INSERT IGNORE INTO `keyword` (keyword, i18n)
VALUES ('Webmail', 0), ('IMAP', 0);

INSERT IGNORE INTO `page_keyword` (page_id, keyword_id)
SELECT p.id, k.id FROM `page` p JOIN `keyword` k
    ON k.keyword IN ('User', 'Webmail', 'IMAP')
 WHERE p.url_id = 'WORDING_WEBMAIL';
INSERT IGNORE INTO `page_keyword` (page_id, keyword_id)
SELECT p.id, k.id FROM `page` p JOIN `keyword` k
    ON k.keyword IN ('Admin', 'Administration Area', 'Config', 'Mail', 'Webmail', 'IMAP')
 WHERE p.url_id = 'WORDING_ADMIN_CONFIG_WEBMAIL';

-- ----------------------------------------------------------
-- User navbar — Webmail, in the alpha-sorted middle pin with Profile/Settings
-- ----------------------------------------------------------

SET @webmail_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_WEBMAIL' LIMIT 1);
SET @user_navbar_id  := (SELECT id FROM `navbar` WHERE name = 'user' LIMIT 1);
SET @user_mid_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @user_navbar_id AND sort_order = 1
     ORDER BY id ASC LIMIT 1
);
SET @existing_webmail_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @webmail_page_id AND np.navbar_id = @user_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @webmail_page_id IS NOT NULL AND @user_mid_pin_id IS NOT NULL AND @existing_webmail_nav IS NULL;
SET @webmail_nav_id := COALESCE(@existing_webmail_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @webmail_nav_id, @user_mid_pin_id, 1, 'WORDING_WEBMAIL', 1, 1, 0
 WHERE @webmail_page_id IS NOT NULL AND @user_mid_pin_id IS NOT NULL AND @webmail_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @webmail_nav_id, @webmail_page_id
 WHERE @webmail_page_id IS NOT NULL AND @webmail_nav_id IS NOT NULL;

-- ----------------------------------------------------------
-- Admin navbar — the IMAP config editor, in the alpha-sorted (last) pin
-- ----------------------------------------------------------

SET @admin_webmail_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_WEBMAIL' LIMIT 1);
SET @admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @admin_navbar_id
     ORDER BY sort_order DESC, id DESC LIMIT 1
);
SET @existing_admin_webmail_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e  ON e.id = ni.id
      JOIN `navbar_pin`   np ON np.id = e.pin_id
     WHERE ni.page_id = @admin_webmail_page_id AND np.navbar_id = @admin_navbar_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @admin_webmail_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_admin_webmail_nav IS NULL;
SET @admin_webmail_nav_id := COALESCE(@existing_admin_webmail_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @admin_webmail_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_CONFIG_WEBMAIL', 1, 1, 0
 WHERE @admin_webmail_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @admin_webmail_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @admin_webmail_nav_id, @admin_webmail_page_id
 WHERE @admin_webmail_page_id IS NOT NULL AND @admin_webmail_nav_id IS NOT NULL;
