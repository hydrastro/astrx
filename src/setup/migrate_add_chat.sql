-- ============================================================
-- AstrX migration: Chat feature (le-chat rebuild)
-- Single-room chat with entry/login, a timed waiting room, presence,
-- private messages, per-user settings, moderation (kick/ban/mute/censor),
-- and an admin config editor.
--
-- Tables:  chat_room, chat_message, chat_presence, chat_pm, chat_settings,
--          banlist_nick (extends the existing banlist).
-- Pages:   chat (shell), chat_stream, chat_users, chat_pm (frames, template=0),
--          chat_login, chat_settings (templated), chat_wait (template=0),
--          admin_config_chat (admin, templated).
-- Idempotent: safe to re-run.
-- ============================================================

-- ----------------------------------------------------------
-- Tables
-- ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS `chat_room`
(
    `id`         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(64)  NOT NULL UNIQUE,
    `topic`      VARCHAR(255) NOT NULL DEFAULT '',
    `min_level`  TINYINT      NOT NULL DEFAULT 0,
    `active`     TINYINT      NOT NULL DEFAULT 1,
    `sort_order` INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `chat_message`
(
    `id`         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `room_id`    INT           NOT NULL,
    `user_id`    BINARY(16)    NULL,
    `nick`       VARCHAR(32)   NULL,
    `color`      VARCHAR(16)   NULL,
    `type`       VARCHAR(16)   NOT NULL DEFAULT 'user',   -- user | system
    `content`    TEXT          NOT NULL,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip`         VARBINARY(16) NULL,
    INDEX idx_room_created (room_id, created_at),
    INDEX idx_expires (expires_at),
    FOREIGN KEY (room_id) REFERENCES chat_room (id) ON DELETE CASCADE ON UPDATE CASCADE,
    FOREIGN KEY (user_id) REFERENCES `user` (id)    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Presence: who is in the chat right now. Identity is `ident` — a member's
-- lowercase-hex user id (32 chars) or a guest's random 32-char token.
CREATE TABLE IF NOT EXISTS `chat_presence`
(
    `ident`      VARCHAR(32)   NOT NULL PRIMARY KEY,
    `is_member`  TINYINT       NOT NULL DEFAULT 0,
    `user_id`    BINARY(16)    NULL,
    `nick`       VARCHAR(64)   NOT NULL,
    `color`      VARCHAR(16)   NULL,
    `role`       TINYINT       NOT NULL DEFAULT 3,        -- UserGroup value (0 user,1 admin,2 mod,3 guest)
    `status`     TINYINT       NOT NULL DEFAULT 0,        -- 0 waiting, 1 active, 2 kicked
    `ip`         VARBINARY(16) NULL,
    `joined_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_last_seen (last_seen),
    INDEX idx_status (status),
    INDEX idx_nick (nick),
    FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Private messages between two idents.
CREATE TABLE IF NOT EXISTS `chat_pm`
(
    `id`          INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `from_ident`  VARCHAR(32) NOT NULL,
    `from_nick`   VARCHAR(64) NOT NULL,
    `from_user_id` BINARY(16) NULL,
    `to_ident`    VARCHAR(32) NOT NULL,
    `to_nick`     VARCHAR(64) NOT NULL,
    `color`       VARCHAR(16) NULL,
    `content`     TEXT        NOT NULL,
    `created_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `read_at`     TIMESTAMP   NULL,
    INDEX idx_to (to_ident, created_at),
    INDEX idx_from (from_ident, created_at),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB;

-- Per-identity display settings.
CREATE TABLE IF NOT EXISTS `chat_settings`
(
    `ident`           VARCHAR(32) NOT NULL PRIMARY KEY,
    `refresh_secs`    INT         NOT NULL DEFAULT 5,
    `messages_shown`  INT         NOT NULL DEFAULT 50,
    `show_timestamps` TINYINT     NOT NULL DEFAULT 1,
    `font_size`       TINYINT     NOT NULL DEFAULT 13,
    `text_color`      VARCHAR(16) NULL,
    `link_conversion` TINYINT     NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Nick bans — extends the existing banlist (mirrors banlist_email/banlist_ip).
CREATE TABLE IF NOT EXISTS `banlist_nick`
(
    `ban_id` INT         NOT NULL PRIMARY KEY,
    `nick`   VARCHAR(64) NOT NULL,
    FOREIGN KEY (ban_id) REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_nick (nick)
) ENGINE=InnoDB;

-- ----------------------------------------------------------
-- Single default room
-- ----------------------------------------------------------

INSERT IGNORE INTO `chat_room` (name, topic, min_level, active, sort_order)
VALUES ('General', '', 0, 1, 0);

-- ----------------------------------------------------------
-- Pages
-- ----------------------------------------------------------
-- Templated pages (template=1): chat shell, entry, settings.
-- Frame/interstitial pages (template=0): stream, users, pm, wait.
-- Admin config page (templated, child of admin).

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_CHAT',            1, 'chat',              1, 1, 0, 0),
    ('WORDING_CHAT_LOGIN',      1, 'chat_login',        1, 1, 0, 0),
    ('WORDING_CHAT_SETTINGS',   1, 'chat_settings',     1, 1, 0, 0),
    ('WORDING_CHAT_STREAM',     1, 'chat_stream',       0, 1, 0, 0),
    ('WORDING_CHAT_USERS',      1, 'chat_users',        0, 1, 0, 0),
    ('WORDING_CHAT_PM',         1, 'chat_pm',           0, 1, 0, 0),
    ('WORDING_CHAT_WAIT',       1, 'chat_wait',         1, 1, 0, 0),
    ('WORDING_ADMIN_CONFIG_CHAT', 1, 'admin_config_chat', 1, 1, 0, 0);

-- The waiting room renders inside the site chrome (template=1); upgrade any
-- row created by an earlier version of this migration on re-run.
UPDATE `page` SET template = 1 WHERE url_id = 'WORDING_CHAT_WAIT';

-- Self-closures + meta + robots for every new page.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page`
 WHERE url_id IN ('WORDING_CHAT','WORDING_CHAT_LOGIN','WORDING_CHAT_SETTINGS',
                  'WORDING_CHAT_STREAM','WORDING_CHAT_USERS','WORDING_CHAT_PM',
                  'WORDING_CHAT_WAIT','WORDING_ADMIN_CONFIG_CHAT');

-- admin_config_chat is a child of the admin root (file_name 'admin') so its
-- template resolves under admin/ and it inherits the admin section.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id
  FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_CONFIG_CHAT';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page`
 WHERE url_id IN ('WORDING_CHAT','WORDING_CHAT_LOGIN','WORDING_CHAT_SETTINGS',
                  'WORDING_CHAT_STREAM','WORDING_CHAT_USERS','WORDING_CHAT_PM',
                  'WORDING_CHAT_WAIT','WORDING_ADMIN_CONFIG_CHAT');

-- The chat shell + login may be indexed; frames, waiting room and admin are not.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id IN ('WORDING_CHAT','WORDING_CHAT_LOGIN');
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page`
 WHERE url_id IN ('WORDING_CHAT_SETTINGS','WORDING_CHAT_STREAM','WORDING_CHAT_USERS',
                  'WORDING_CHAT_PM','WORDING_CHAT_WAIT','WORDING_ADMIN_CONFIG_CHAT');

-- ----------------------------------------------------------
-- Public navbar entry for the chat shell
-- ----------------------------------------------------------

SET @chat_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_CHAT' LIMIT 1);
SET @public_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @public_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @public_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_chat_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @chat_page_id AND e.pin_id = @public_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @chat_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @existing_chat_nav IS NULL;
SET @chat_nav_id := COALESCE(@existing_chat_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @chat_nav_id, @public_pin_id, 1, 'WORDING_CHAT', 1, 1, 0
 WHERE @chat_page_id IS NOT NULL AND @public_pin_id IS NOT NULL AND @chat_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @chat_nav_id, @chat_page_id
 WHERE @chat_page_id IS NOT NULL AND @chat_nav_id IS NOT NULL;

-- ----------------------------------------------------------
-- Admin navbar entry for the chat config editor (pin 6, alpha group)
-- ----------------------------------------------------------

SET @admin_chat_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_CHAT' LIMIT 1);
SET @admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @admin_navbar_id
     ORDER BY sort_order DESC, id DESC LIMIT 1
);
SET @existing_admin_chat_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @admin_chat_page_id AND e.pin_id = @admin_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @admin_chat_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @existing_admin_chat_nav IS NULL;
SET @admin_chat_nav_id := COALESCE(@existing_admin_chat_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @admin_chat_nav_id, @admin_pin_id, 1, 'WORDING_ADMIN_CONFIG_CHAT', 1, 1, 0
 WHERE @admin_chat_page_id IS NOT NULL AND @admin_pin_id IS NOT NULL AND @admin_chat_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @admin_chat_nav_id, @admin_chat_page_id
 WHERE @admin_chat_page_id IS NOT NULL AND @admin_chat_nav_id IS NOT NULL;

-- Verify:
--   SELECT * FROM chat_room;
--   SELECT url_id, file_name, template FROM page WHERE url_id LIKE 'WORDING_CHAT%' OR url_id='WORDING_ADMIN_CONFIG_CHAT';
