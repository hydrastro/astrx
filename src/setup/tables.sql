USE content_manager;


-- ============================================================
-- SETUP / MIGRATIONS
-- ============================================================

CREATE TABLE IF NOT EXISTS `migration`
(
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `file_name`   VARCHAR(255) NOT NULL UNIQUE,
    `checksum`    CHAR(64)     NOT NULL,
    `executed_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PAGE SYSTEM
-- ============================================================

CREATE TABLE `page`
(
    `id`         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `url_id`     VARCHAR(64) NOT NULL UNIQUE,
    `i18n`       TINYINT     NOT NULL DEFAULT 0,
    `file_name`  VARCHAR(64) NOT NULL,
    `template`   TINYINT     NOT NULL DEFAULT 1,
    `controller` TINYINT     NOT NULL DEFAULT 0,
    `hidden`       TINYINT     NOT NULL DEFAULT 0,
    `comments`     TINYINT     NOT NULL DEFAULT 0,
    `api_enabled`  TINYINT     NOT NULL DEFAULT 0
);

CREATE TABLE `page_robots`
(
    `page_id` INT     NOT NULL PRIMARY KEY,
    `index`   TINYINT NOT NULL DEFAULT 1,
    `follow`  TINYINT NOT NULL DEFAULT 1,
    FOREIGN KEY (page_id) REFERENCES page (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `page_meta`
(
    `page_id`     INT          NOT NULL PRIMARY KEY,
    `title`       VARCHAR(64)  NOT NULL DEFAULT '',
    `description` VARCHAR(160) NOT NULL DEFAULT '',
    FOREIGN KEY (page_id) REFERENCES page (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `page_closure`
(
    `ancestor`   INT NOT NULL,
    `descendant` INT NOT NULL,
    PRIMARY KEY (ancestor, descendant),
    FOREIGN KEY (ancestor)   REFERENCES page (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (descendant) REFERENCES page (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `keyword`
(
    `id`      INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `keyword` VARCHAR(64) NOT NULL,
    `i18n`    TINYINT     NOT NULL DEFAULT 0,
    -- R11: natural key so re-running tables.sql (setup re-entry runs it in full,
    -- unconditionally) can't duplicate seed keywords; paired with INSERT IGNORE
    -- on the seed below. keyword is only ever populated by that seed.
    UNIQUE KEY `uq_keyword` (`keyword`, `i18n`)
);

CREATE TABLE `page_keyword`
(
    `page_id`    INT NOT NULL,
    `keyword_id` INT NOT NULL,
    PRIMARY KEY (page_id, keyword_id),
    FOREIGN KEY (page_id)    REFERENCES page    (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (keyword_id) REFERENCES keyword (id) ON UPDATE CASCADE ON DELETE CASCADE
);


-- ============================================================
-- TEMPLATE SYSTEM
-- ============================================================

CREATE TABLE `template`
(
    `id`        INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `file_name` VARCHAR(64) NOT NULL
);

CREATE TABLE `page_template`
(
    `page_id`     INT NOT NULL PRIMARY KEY,
    `template_id` INT NOT NULL,
    FOREIGN KEY (page_id)     REFERENCES page     (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES template (id) ON UPDATE CASCADE ON DELETE CASCADE
);


-- ============================================================
-- NAVIGATION BAR
-- ============================================================

CREATE TABLE `navbar`
(
    `id`   INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(64) NOT NULL,
    -- R11: navbar names are unique — only 'public'/'user'/'admin' are ever
    -- created (no dynamic navbar creation), so this + INSERT IGNORE makes the
    -- seed idempotent when tables.sql is re-run on setup re-entry.
    UNIQUE KEY `uq_navbar_name` (`name`)
);

CREATE TABLE `navbar_pin`
(
    `id`         INT     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `navbar_id`  INT     NOT NULL,
    `sort_order` INT     NOT NULL DEFAULT 0,
    `sort_mode`  TINYINT NOT NULL DEFAULT 0,
    FOREIGN KEY (navbar_id) REFERENCES navbar (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_navbar (navbar_id)
);

CREATE TABLE `navbar_entry_ids`
(
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY
);

CREATE TABLE `navbar_entry`
(
    `id`         INT         NOT NULL PRIMARY KEY,
    `pin_id`     INT         NOT NULL,
    `internal`   TINYINT     NOT NULL,
    `name`       VARCHAR(64) NOT NULL,
    `i18n`       TINYINT     NOT NULL DEFAULT 0,
    `active`     TINYINT     NOT NULL DEFAULT 1,
    `sort_order` INT         NULL,
    FOREIGN KEY (id)     REFERENCES navbar_entry_ids (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (pin_id) REFERENCES navbar_pin       (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_pin (pin_id)
);

CREATE TABLE `navbar_internal`
(
    `id`      INT NOT NULL PRIMARY KEY,
    `page_id` INT NOT NULL,
    FOREIGN KEY (id)      REFERENCES navbar_entry (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES page         (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `navbar_external`
(
    `id`  INT           NOT NULL PRIMARY KEY,
    `url` VARCHAR(2083) NOT NULL,
    FOREIGN KEY (id) REFERENCES navbar_entry (id) ON UPDATE CASCADE ON DELETE CASCADE
);


-- ============================================================
-- SESSION
-- ============================================================

CREATE TABLE `session`
(
    `id`          VARCHAR(128) NOT NULL PRIMARY KEY,
    `timestamp`   INT UNSIGNED NOT NULL,
    `data`        MEDIUMBLOB   NOT NULL DEFAULT '',
    -- Grace-period handover for session-ID regeneration. SecureSessionHandler
    -- reads these but tolerates their absence (SELECT *), so a legacy `session`
    -- table without them still works — it just skips the handover window.
    `replaced_by` CHAR(128)    NULL DEFAULT NULL,
    `replace_at`  INT UNSIGNED NULL DEFAULT NULL
);


-- ============================================================
-- VIEWS
-- ============================================================

CREATE VIEW resolved_page AS
SELECT p.id,
       p.url_id,
       p.i18n,
       p.file_name,
       p.template,
       p.controller,
       p.hidden,
       p.comments,
       p.api_enabled,
       COALESCE(pr.`index`, 1) AS `index`,
       COALESCE(pr.follow, 1) AS follow,
       COALESCE(pm.title, '') AS title,
       COALESCE(pm.description, '') AS description,
       COALESCE(t.file_name, '') AS template_file_name
FROM `page` p
         LEFT JOIN `page_robots`   pr ON pr.page_id   = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id   = p.id
         LEFT JOIN `page_template` pt ON pt.page_id   = p.id
         LEFT JOIN `template`      t  ON t.id          = pt.template_id;

CREATE VIEW resolved_navbar AS
SELECT e.id,
       e.internal,
       e.name,
       e.i18n,
       e.active,
       e.sort_order  AS entry_sort_order,
       np.id         AS pin_id,
       np.sort_order AS pin_sort_order,
       np.sort_mode  AS pin_sort_mode,
       np.navbar_id,
       ni.page_id,
       ne.url,
       p.url_id,
       p.file_name   AS page_file_name,
       p.i18n        AS page_i18n
FROM `navbar_entry` e
         JOIN      `navbar_pin`      np ON np.id    = e.pin_id
         LEFT JOIN `navbar_internal` ni ON ni.id    = e.id
         LEFT JOIN `navbar_external` ne ON ne.id    = e.id
         LEFT JOIN `page`            p  ON p.id     = ni.page_id;


-- ============================================================
-- USER SYSTEM
-- ============================================================

CREATE TABLE `user`
(
    `id`               BINARY(16)   NOT NULL PRIMARY KEY,
    `username`         VARCHAR(64)  NULL UNIQUE,
    `password`         VARCHAR(255) NULL,
    `mailbox`          VARCHAR(320) NULL UNIQUE,
    `email`            VARCHAR(320) NULL UNIQUE,
    `display_name`     VARCHAR(64)  NULL,
    `type`             TINYINT      NOT NULL DEFAULT 0,
    `birth`            DATE         NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_access`      TIMESTAMP    NULL,
    `login_attempts`   INT          NOT NULL DEFAULT 0,
    `login_locked_until` INT UNSIGNED NULL,                  -- unix ts; brute-force lockout expiry (NULL = not locked)
    `verified`         TINYINT      NOT NULL DEFAULT 0,
    `avatar`           TINYINT      NOT NULL DEFAULT 0,
    `deleted`          TINYINT      NOT NULL DEFAULT 0,
    `deletion_mode`    VARCHAR(16)  NULL,                 -- DeletionMode enum value: none|full_delete|hard_redact|soft_redact|keep_visible|keep_suspended
    `theme`            VARCHAR(64)  NULL,                 -- per-user theme override, NULL = use global
    `token_hash`       VARCHAR(255) NULL,
    `token_type`       TINYINT      NULL,
    `token_used`       TINYINT      NOT NULL DEFAULT 0,
    `token_expires_at` TIMESTAMP    NULL,
    INDEX idx_username (username),
    INDEX idx_email    (email),
    INDEX idx_mailbox  (mailbox),
    INDEX idx_deleted  (deleted)
);

-- Ghost user (all-zero id) holds re-assigned content from hard-redacted users.
-- Created at install time so the ON DELETE SET NULL pattern always has a target.
INSERT IGNORE INTO `user` (id, username, type, verified, deleted)
VALUES (UNHEX('00000000000000000000000000000000'),
        '[deleted]', 0, 0, 0);


-- ============================================================
-- CONTENT: NEWS
-- ============================================================

CREATE TABLE `news`
(
    `id`         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(64) NOT NULL,
    `content`    TEXT        NOT NULL,
    `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `hidden`     TINYINT     NOT NULL DEFAULT 0
);


-- ============================================================
-- CONTENT: COMMENTS
-- ============================================================

CREATE TABLE `comment`
(
    `id`         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `page_id`    INT           NOT NULL,
    `item_id`    INT           NULL,
    `user_id`    BINARY(16)    NULL,
    `name`       VARCHAR(64)   NULL,
    `email`      VARCHAR(320)  NULL,
    `content`    TEXT          NOT NULL,
    `reply_to`   INT           NULL,
    `ip`         VARBINARY(16) NULL,
    `hidden`     TINYINT       NOT NULL DEFAULT 0,
    `flagged`    TINYINT       NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id)  REFERENCES page    (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES user    (id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (reply_to) REFERENCES comment (id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_page    (page_id),
    INDEX idx_item    (item_id),
    INDEX idx_user    (user_id),
    INDEX idx_created (created_at)
);

CREATE TABLE `mute`
(
    `id`         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`    BINARY(16)    NULL,
    `ip`         VARBINARY(16) NULL,
    `page_id`    INT           NULL,
    `expires_at` TIMESTAMP     NOT NULL,
    FOREIGN KEY (user_id) REFERENCES user (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES page (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_user    (user_id),
    INDEX idx_ip      (ip),
    INDEX idx_expires (expires_at)
);


-- ============================================================
-- SECURITY: CAPTCHA
-- ============================================================

CREATE TABLE `captcha`
(
    `id`         CHAR(32)    NOT NULL PRIMARY KEY,
    `text`       VARCHAR(32) NOT NULL,
    `expires_at` TIMESTAMP   NOT NULL,
    INDEX idx_expires (expires_at)
);


-- ============================================================
-- SECURITY: BANLIST
-- ============================================================

CREATE TABLE `banlist`
(
    `id`            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ban_route`     VARCHAR(64)  NOT NULL DEFAULT 'permanent',
    `penalty_round` SMALLINT     NOT NULL DEFAULT 0,
    `tries`         SMALLINT     NOT NULL DEFAULT 0,
    `reason`        TEXT         NOT NULL DEFAULT '',
    `start`         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `end`           TIMESTAMP    NULL,
    `check_time`    TIMESTAMP    NULL,
    `active`        TINYINT      NOT NULL DEFAULT 1,
    INDEX idx_active (active),
    INDEX idx_route  (ban_route)
);

CREATE TABLE `banlist_user`
(
    `ban_id`  INT        NOT NULL PRIMARY KEY,
    `user_id` BINARY(16) NOT NULL,
    FOREIGN KEY (ban_id)  REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES user    (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `banlist_email`
(
    `ban_id` INT          NOT NULL PRIMARY KEY,
    `email`  VARCHAR(320) NOT NULL,
    FOREIGN KEY (ban_id) REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_email (email)
);

CREATE TABLE `banlist_ip`
(
    `ban_id`     INT        NOT NULL PRIMARY KEY,
    `network`    BINARY(16) NOT NULL,
    `prefix_len` TINYINT    NOT NULL,
    FOREIGN KEY (ban_id) REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_network (network)
);


-- ============================================================
-- SITE CONFIG
-- ============================================================

CREATE TABLE `site_config`
(
    `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT        NOT NULL DEFAULT ''
);


-- ============================================================
-- WEBMAIL
-- ============================================================

CREATE TABLE `webmail_trusted_sender`
(
    `user_id`      BINARY(16)   NOT NULL,
    `sender_email` VARCHAR(320) NOT NULL,
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `sender_email`),
    FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
);



-- ============================================================
-- DIAGNOSTICS: per-group visibility + level overrides
-- ============================================================

CREATE TABLE `diagnostic_visibility`
(
    `code`       VARCHAR(128) NOT NULL,
    `group_name` VARCHAR(32)  NOT NULL,
    PRIMARY KEY (`code`, `group_name`),
    INDEX idx_code (`code`)
);

CREATE TABLE `diagnostic_level_override`
(
    `code`  VARCHAR(128) NOT NULL PRIMARY KEY,
    `level` TINYINT      NOT NULL
);


-- ============================================================
-- ADMIN AUDIT LOG
-- ============================================================

CREATE TABLE `admin_audit_log`
(
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id`    BINARY(16)      NOT NULL,
    `username`   VARCHAR(64)     NOT NULL,
    `action`     VARCHAR(64)     NOT NULL,
    `resource`   VARCHAR(128)    NOT NULL DEFAULT '',
    `detail`     TEXT            NOT NULL DEFAULT '',
    `ip`         VARCHAR(45)     NOT NULL DEFAULT '',
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_action`     (`action`),
    INDEX `idx_user_id`    (`user_id`),
    INDEX `idx_created_at` (`created_at`)
);


-- ============================================================
-- DATA INSERTIONS
-- ============================================================

-- ----------------------------------------------------------
-- Pages
--
-- ID map:
--   1  main                    9  user (section root)
--   2  error                  10  avatar
--   3  login                  11  admin_banlist
--   4  register               12  admin_comments
--   5  recover                13  admin_navbar
--   6  profile                14  admin_news
--   7  user_settings          15  admin_notes
--   8  user_home              16  admin_pages
--                             17  admin_users
--                             18  admin (section root)
--                             19  logout
--                             20  admin_config_system
--                             21  admin_config_access
--                             22  admin_config_content
--                             23  admin_config_comments
--                             24  admin_config_captcha
--                             25  admin_config_users
--                             26  admin_config_mail
--                             27  webmail
--                             28  admin_config_webmail
--                             29  admin_audit_log
-- ----------------------------------------------------------

INSERT INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_MAIN',                    1, 'main',                   1, 1, 0, 1),  -- id=1
    ('WORDING_ERROR',                   1, 'error',                  1, 1, 1, 0),  -- id=2
    ('WORDING_LOGIN',                   1, 'login',                  1, 1, 0, 0),  -- id=3
    ('WORDING_REGISTER',                1, 'register',               1, 1, 0, 0),  -- id=4
    ('WORDING_RECOVER',                 1, 'recover',                1, 1, 0, 0),  -- id=5
    ('WORDING_PROFILE',                 1, 'profile',                1, 1, 0, 0),  -- id=6
    ('WORDING_SETTINGS',                1, 'user_settings',          1, 1, 0, 0),  -- id=7
    ('WORDING_USER_HOME',               1, 'user_home',              1, 1, 0, 0),  -- id=8
    ('WORDING_USER',                    1, 'user',                   1, 1, 0, 0),  -- id=9
    ('avatar',                          0, 'avatar',                 0, 1, 0, 0),  -- id=10
    ('WORDING_ADMIN_BANLIST',           1, 'admin_banlist',          1, 1, 0, 0),  -- id=11
    ('WORDING_ADMIN_COMMENTS',          1, 'admin_comments',         1, 1, 0, 0),  -- id=12
    ('WORDING_ADMIN_NAVBAR',            1, 'admin_navbar',           1, 1, 0, 0),  -- id=13
    ('WORDING_ADMIN_NEWS',              1, 'admin_news',             1, 1, 0, 0),  -- id=14
    ('WORDING_ADMIN_NOTES',             1, 'admin_notes',            1, 1, 0, 0),  -- id=15
    ('WORDING_ADMIN_PAGES',             1, 'admin_pages',            1, 1, 0, 0),  -- id=16
    ('WORDING_ADMIN_USERS',             1, 'admin_users',            1, 1, 0, 0),  -- id=17
    ('WORDING_ADMIN',                   1, 'admin',                  1, 1, 0, 0),  -- id=18
    ('WORDING_LOGOUT',                  1, 'logout',                 0, 1, 0, 0),  -- id=19
    ('WORDING_ADMIN_CONFIG_SYSTEM',     1, 'admin_config_system',    1, 1, 0, 0),  -- id=20
    ('WORDING_ADMIN_CONFIG_ACCESS',     1, 'admin_config_access',    1, 1, 0, 0),  -- id=21
    ('WORDING_ADMIN_CONFIG_CONTENT',    1, 'admin_config_content',   1, 1, 0, 0),  -- id=22
    ('WORDING_ADMIN_CONFIG_COMMENTS',   1, 'admin_config_comments',  1, 1, 0, 0),  -- id=23
    ('WORDING_ADMIN_CONFIG_CAPTCHA',    1, 'admin_config_captcha',   1, 1, 0, 0),  -- id=24
    ('WORDING_ADMIN_CONFIG_USERS',      1, 'admin_config_users',     1, 1, 0, 0),  -- id=25
    ('WORDING_ADMIN_CONFIG_MAIL',       1, 'admin_config_mail',      1, 1, 0, 0),  -- id=26
    ('WORDING_WEBMAIL',                 1, 'webmail',                1, 1, 0, 0),  -- id=27
    ('WORDING_ADMIN_CONFIG_WEBMAIL',    1, 'admin_config_webmail',   1, 1, 0, 0),  -- id=28
    ('WORDING_ADMIN_AUDIT_LOG',         1, 'admin_audit_log',        1, 1, 0, 0),  -- id=29
    ('WORDING_ADMIN_THEMES',            1, 'admin_themes',           1, 1, 0, 0);  -- id=30


INSERT INTO `page_robots` (page_id, `index`, follow)
VALUES
    (1,1,1),(2,0,0),(3,1,1),(4,1,1),(5,1,1),(6,0,0),(7,0,0),(8,0,0),(9,1,1),
    (10,0,0),(11,0,0),(12,0,0),(13,0,0),(14,0,0),(15,0,0),(16,0,0),(17,0,0),(18,0,0),(19,0,0),
    (20,0,0),(21,0,0),(22,0,0),(23,0,0),(24,0,0),(25,0,0),(26,0,0),
    (27,0,0),(28,0,0),(29,0,0),(30,0,0);


INSERT INTO `page_meta` (page_id, title, description)
VALUES
    (1,  'My Website',                  'This is my awesome website!'),
    (2,  'Error',                       'An error occurred.'),
    (3,  'Login',                       'Log in to your account.'),
    (4,  'Register',                    'Create a new account.'),
    (5,  'Recover',                     'Recover your account password.'),
    (6,  'User Profile',                'View a user profile.'),
    (7,  'Settings',                    'Manage your account settings.'),
    (8,  'Home',                        'Welcome to your home page.'),
    (9,  'User Area',                   'Log in or create your account.'),
    (10, '',                            ''),
    (11, 'Admin — Banlist',             'Manage the banlist.'),
    (12, 'Admin — Comments',            'Moderate site comments.'),
    (13, 'Admin — Navbar',              'Edit the navigation bar.'),
    (14, 'Admin — News',                'Manage news posts.'),
    (15, 'Admin — Notes',               'Personal admin notes.'),
    (16, 'Admin — Pages',               'Manage site pages.'),
    (17, 'Admin — Users',               'Manage user accounts.'),
    (18, 'Administration',              'Administration area.'),
    (19, 'Logout',                      ''),
    (20, 'Config — System',             'Edit core system configuration.'),
    (21, 'Config — Access & Security',  'Edit auth grants and banlist routes.'),
    (22, 'Config — Content',            'Edit news pagination settings.'),
    (23, 'Config — Comments',           'Edit comment service configuration.'),
    (24, 'Config — Captcha',            'Edit captcha settings.'),
    (25, 'Config — Users',              'Edit user service configuration.'),
    (26, 'Config — Mail',               'Edit mail configuration.'),
    (27, 'Webmail',                     'Read and send emails from your mailbox.'),
    (28, 'Config — Webmail / IMAP',     'Edit IMAP and webmail configuration.'),
    (29, 'Admin — Audit Log',           'History of all admin actions.'),
    (30, 'Admin — Themes',              'Choose the global site theme.');


INSERT INTO `page_closure` (ancestor, descendant)
VALUES
    -- Self-references (every page is its own ancestor at depth 0)
    (1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),(8,8),(9,9),(10,10),
    (11,11),(12,12),(13,13),(14,14),(15,15),(16,16),(17,17),(18,18),(19,19),
    (20,20),(21,21),(22,22),(23,23),(24,24),(25,25),(26,26),
    (27,27),(28,28),(29,29),(30,30),
    -- User section children (9 is ancestor)
    (9,3),(9,4),(9,5),(9,6),(9,7),(9,8),(9,19),(9,27),
    -- Admin section children (18 is ancestor)
    (18,11),(18,12),(18,13),(18,14),(18,15),(18,16),(18,17),
    (18,20),(18,21),(18,22),(18,23),(18,24),(18,25),(18,26),
    (18,28),(18,29),(18,30);


INSERT IGNORE INTO `keyword` (keyword, i18n)
VALUES
    ('WORDING_MAIN_PAGE',   1), ('WORDING_INDEX',       1), ('User',                0),
    ('Profile',             0), ('Login',               0), ('Register',            0),
    ('Main Page',           0), ('User Area',           0), ('Registration',        0),
    ('Recover',             0), ('Lost Password',       0), ('Admin',               0),
    ('Administration Area', 0), ('Settings',            0), ('Banlist',             0),
    ('Comments',            0), ('Navbar',              0), ('News',                0),
    ('Notes',               0), ('Pages',               0), ('Users',               0),
    ('Config',              0), ('System',              0), ('Access',              0),
    ('Security',            0), ('Content',             0), ('Captcha',             0),
    ('Mail',                0), ('Webmail',             0), ('IMAP',                0),
    ('Audit',               0), ('Log',                 0);


INSERT INTO `page_keyword` (page_id, keyword_id)
VALUES
    (1,1),(1,2),(1,7),(3,5),(3,3),(4,6),(4,3),(4,8),(4,9),(5,10),(5,11),(5,3),(5,1),
    (6,3),(6,4),(7,3),(7,8),(7,14),(9,3),(9,8),(18,12),(18,13),(18,14),
    (11,12),(11,13),(11,14),(11,15),(12,12),(12,13),(12,14),(12,16),
    (13,12),(13,13),(13,14),(13,17),(14,12),(14,13),(14,14),(14,18),
    (15,12),(15,13),(15,14),(15,19),(16,12),(16,13),(16,14),(16,20),
    (17,12),(17,13),(17,14),(17,21),
    (20,12),(20,13),(20,22),(20,23),(21,12),(21,13),(21,22),(21,24),(21,25),
    (22,12),(22,13),(22,22),(22,26),(23,12),(23,13),(23,22),(23,16),
    (24,12),(24,13),(24,22),(24,27),(25,12),(25,13),(25,22),(25,21),
    (26,12),(26,13),(26,22),(26,28),
    (27,3),(27,29),(27,30),(28,12),(28,13),(28,22),(28,28),(28,29),(28,30),
    (29,12),(29,13),(29,31),(29,32);


-- ----------------------------------------------------------
-- Navbars
-- ----------------------------------------------------------

INSERT IGNORE INTO `navbar` (name) VALUES ('public'), ('user'), ('admin');

-- R11: navbar_pin has NO natural UNIQUE key — two pins in one navbar can
-- legitimately share a (navbar_id, sort_order) position (the admin editor's
-- addPin takes a caller-supplied sort_order, default 0), so a UNIQUE would
-- regress navbar editing. Instead seed the six pins ONLY when the table is
-- empty, so re-running tables.sql (setup re-entry) can't duplicate them while
-- fresh installs still get ids 1..6 in order (navbar_entry FKs depend on that).
INSERT INTO `navbar_pin` (navbar_id, sort_order, sort_mode)
SELECT `navbar_id`, `sort_order`, `sort_mode` FROM (
              SELECT 1 AS `navbar_id`, 0 AS `sort_order`, 1 AS `sort_mode`  -- id=1 public — custom
    UNION ALL SELECT 2, 0, 1                                               -- id=2 user first pin (custom: User Home)
    UNION ALL SELECT 2, 1, 0                                               -- id=3 user middle pin (alpha)
    UNION ALL SELECT 2, 2, 1                                               -- id=4 user last pin (custom: Logout)
    UNION ALL SELECT 3, 0, 1                                               -- id=5 admin: Dashboard
    UNION ALL SELECT 3, 1, 0                                               -- id=6 admin: everything else
) AS `seed`
WHERE NOT EXISTS (SELECT 1 FROM `navbar_pin`);

-- 24 navbar entries total
INSERT INTO `navbar_entry_ids` ()
VALUES (),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),();

INSERT INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
VALUES
    -- Public navbar
    (1,  1, 1, 'WORDING_HOME',                   1, 1, 0),
    (2,  1, 1, 'WORDING_USER',                   1, 1, 1),
    (3,  1, 0, 'Test',                            0, 0, 2),
    (4,  1, 0, 'Ext',                             0, 0, 3),
    -- User navbar
    (5,  2, 1, 'WORDING_USER_HOME',              1, 1, 0),
    (6,  3, 1, 'WORDING_PROFILE',                1, 1, 0),
    (7,  3, 1, 'WORDING_SETTINGS',               1, 1, 0),
    (8,  4, 1, 'WORDING_LOGOUT',                 1, 1, 0),
    -- Admin navbar — Dashboard (pin 5, custom)
    (9,  5, 1, 'WORDING_ADMIN',                  1, 1, 0),
    -- Admin navbar — all other pages (pin 6, alpha-sorted)
    (10, 6, 1, 'WORDING_ADMIN_NEWS',             1, 1, 0),
    (11, 6, 1, 'WORDING_ADMIN_COMMENTS',         1, 1, 0),
    (12, 6, 1, 'WORDING_ADMIN_USERS',            1, 1, 0),
    (13, 6, 1, 'WORDING_ADMIN_BANLIST',          1, 1, 0),
    (14, 6, 1, 'WORDING_ADMIN_NAVBAR',           1, 1, 0),
    (15, 6, 1, 'WORDING_ADMIN_PAGES',            1, 1, 0),
    (16, 6, 1, 'WORDING_ADMIN_NOTES',            1, 1, 0),
    (17, 6, 1, 'WORDING_ADMIN_CONFIG_SYSTEM',    1, 1, 0),
    (18, 6, 1, 'WORDING_ADMIN_CONFIG_ACCESS',    1, 1, 0),
    (19, 6, 1, 'WORDING_ADMIN_CONFIG_CAPTCHA',   1, 1, 0),
    (20, 6, 1, 'WORDING_ADMIN_CONFIG_MAIL',      1, 1, 0),
    -- User navbar — Webmail (pin 3, alpha-sorted alongside Profile/Settings)
    (21, 3, 1, 'WORDING_WEBMAIL',                1, 1, 0),
    -- Admin navbar — Webmail IMAP config (pin 6, alpha-sorted)
    (22, 6, 1, 'WORDING_ADMIN_CONFIG_WEBMAIL',   1, 1, 0),
    -- Admin navbar — Audit Log and Themes (pin 6, alpha-sorted)
    (23, 6, 1, 'WORDING_ADMIN_AUDIT_LOG',        1, 1, 0),
    (24, 6, 1, 'WORDING_ADMIN_THEMES',           1, 1, 0);

INSERT INTO `navbar_internal` (id, page_id)
VALUES
    -- Entries with known stable page IDs
    (1,1),(2,9),(5,8),(6,6),(7,7),(8,19),
    (9,18),(10,14),(11,12),(12,17),(13,11),(14,13),(15,16),(16,15),
    (17,20),(18,21),(19,24),(20,26),
    -- New pages (ids 27, 28, 29, 30)
    (21,27),(22,28),(23,29),(24,30);

-- Example external links are intentionally inert placeholders: any real clearnet
-- URL on a hidden service is a deanonymization vector the moment it is enabled.
-- Replace with your own (onion) URLs before activating these entries.
INSERT INTO `navbar_external` (id, url)
VALUES (3,'#'),(4,'#');


-- ----------------------------------------------------------
-- First administrator
-- ----------------------------------------------------------
-- Intentionally not seeded here. The first administrator is created by
-- public/setup.php from the credentials entered during setup, so there is no
-- public default admin account.

-- ----------------------------------------------------------
-- Captcha test page (remove before production)
-- ----------------------------------------------------------

-- Intentionally NOT seeded (security): 'captcha-test' was an unauthenticated,
-- CSRF-less test endpoint wired to the production captcha table. It is no longer
-- seeded. Run migrate_remove_captcha_test.sql to drop it from an existing DB,
-- and delete src/AstrX/Controller/CaptchaTestController.php.


-- ============================================================
-- CONSOLIDATED MIGRATIONS (folded in setup order; formerly migrate_*.sql)
-- Each block ran as a separate migration; concatenated here so a fresh
-- install needs only this one file. All are idempotent (IF NOT EXISTS /
-- INSERT IGNORE / safe ALTER-MODIFY / order-preserved UPDATE+DELETE).
-- ============================================================


-- ---------- migrate_add_chat.sql ----------
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
    `font_size`       TINYINT     NOT NULL DEFAULT 16,
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


-- ---------- migrate_add_login_lockout.sql ----------
-- ============================================================
-- AstrX migration: brute-force login lockout column (fix M4)
-- Adds a per-account temporary-lockout expiry to the `user` table.
-- Safe to re-run (idempotent ADD COLUMN IF NOT EXISTS).
-- ============================================================

-- login_locked_until : unix timestamp until which login is refused for this
--                      account, set once `login_lockout_threshold` consecutive
--                      failed logins are reached and held for
--                      `login_lockout_cooldown` seconds. NULL = not locked.
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `login_locked_until` INT UNSIGNED NULL AFTER `login_attempts`;


-- ---------- migrate_api.sql ----------
-- ============================================================
-- AstrX migration: API core (fix99)
-- Adds the api_key table and the page.api_enabled flag.
-- Safe to re-run.
-- ============================================================

-- 1. Per-page opt-in flag. Defaults to 0 — pages must be explicitly
--    api-enabled before they appear under /api/.
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0
    AFTER `comments`;

-- 2. Rebuild resolved_page view to include api_enabled.
--    DROP + CREATE is required because MariaDB doesn't support ALTER VIEW
--    column lists. The view is just a read projection over the underlying
--    tables — it has no data of its own.
DROP VIEW IF EXISTS `resolved_page`;
CREATE VIEW `resolved_page` AS
SELECT p.id,
       p.url_id,
       p.i18n,
       p.file_name,
       p.template,
       p.controller,
       p.hidden,
       p.comments,
       p.api_enabled,
       pr.`index`,
       pr.follow,
       pm.title,
       pm.description,
       t.file_name AS template_file_name
FROM `page` p
         LEFT JOIN `page_robots`   pr ON pr.page_id   = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id   = p.id
         LEFT JOIN `page_template` pt ON pt.page_id   = p.id
         LEFT JOIN `template`      t  ON t.id          = pt.template_id;

-- 3. API keys. One user can have many keys; each key has a label so the
--    user can remember what it's for ("My CLI tool", "Mobile app", etc.).
--    key_hash is sha256(raw_key) — the raw key is shown to the user once
--    on creation and is never recoverable.
CREATE TABLE IF NOT EXISTS `api_key`
(
    `id`           BINARY(16)   NOT NULL PRIMARY KEY,
    `user_id`      BINARY(16)   NOT NULL,
    `label`        VARCHAR(64)  NOT NULL,
    `key_hash`     CHAR(64)     NOT NULL UNIQUE,    -- sha256 hex, 64 chars
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` TIMESTAMP    NULL,
    `expires_at`   TIMESTAMP    NULL,                -- NULL = never expires
    `revoked`      TINYINT      NOT NULL DEFAULT 0,
    FOREIGN KEY (`user_id`) REFERENCES `user`(`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    INDEX idx_user (`user_id`),
    INDEX idx_revoked (`revoked`)
);


-- ---------- migrate_api_profile.sql ----------
-- ============================================================
-- AstrX migration: enable the profile page as the first API endpoint (fix100)
-- ============================================================

-- The profile page is now a public read-only endpoint exposing
-- safe-to-share user fields. Tagged at the controller via
-- ContextScope::SHARED — see ProfileController.php.
UPDATE `page` SET `api_enabled` = 1 WHERE `url_id` = 'WORDING_PROFILE';


-- ---------- migrate_captcha_abuse.sql ----------
-- ============================================================
-- AstrX migration: captcha abuse policy columns (fix105)
-- Limits how often a captcha can be reloaded and adds a cooldown.
-- Safe to re-run.
-- ============================================================

-- regen_count : number of times this captcha has been reloaded.
--               Capped at CaptchaService::MAX_REGENS (default 5) — past that,
--               the regenerate call is a no-op and returns the existing image.
-- last_regen_at: timestamp of the most recent regeneration. Used by the
--               cooldown check (default 2s between regens for the same id).
ALTER TABLE `captcha`
    ADD COLUMN IF NOT EXISTS `regen_count`   INT       NOT NULL DEFAULT 0 AFTER `expires_at`,
    ADD COLUMN IF NOT EXISTS `last_regen_at` TIMESTAMP NULL              AFTER `regen_count`;


-- ---------- migrate_captcha_iframe.sql ----------
-- ============================================================
-- AstrX migration: iframe-reloadable captcha pages (fix111)
-- Re-delivery of fix104's migrate_captcha_iframe.sql, but without the
-- api_enabled column reference — that column only exists after
-- migrate_api.sql has run, and the original migration would silently
-- fail on a DB that hadn't seen the API migration yet.
--
-- This version uses only columns present in the canonical schema in
-- src/setup/tables.sql, so it works regardless of which other
-- migrations have or haven't been applied.
--
-- Safe to re-run.
-- ============================================================

-- 1. The two page rows. Default api_enabled=0 is fine if the column exists;
--    if it doesn't, the column is simply not referenced.
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_CAPTCHA_IMAGE', 1, 'captcha_image', 0, 1, 0, 0),
    ('WORDING_CAPTCHA_FRAME', 1, 'captcha_frame', 0, 1, 0, 0);

-- 2. Closure self-references (used by the routing layer for ancestor lookups).
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- 3. Page meta — both hidden from search engines via page_robots.
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- ============================================================
-- VERIFICATION (run separately to check state):
--
-- SELECT id, url_id, file_name, template, controller, hidden
--   FROM `page`
--  WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');
--
-- Expected output: 2 rows, both with template=0, controller=1, hidden=1.
-- If you get 0 rows, this migration didn't succeed — check the schema
-- of the `page` table and re-run.
-- ============================================================


-- ---------- migrate_captcha_unhide.sql ----------
-- ============================================================
-- AstrX migration: unhide captcha-iframe pages (fix112)
--
-- Context: fix104 created the captcha-image and captcha-frame page rows
-- with hidden=1, on the assumption that `hidden` only meant "hide from
-- the navbar". It doesn't — the framework's ContentManager also 404s
-- any hidden page for non-admin users:
--
--     if (!$adminViewingHidden && $page->hidden) {
--         http_response_code(HttpStatus::NOT_FOUND->value);
--     }
--
-- These captcha endpoints are hit by anonymous users during registration,
-- so they MUST be reachable without admin perms. The right pattern (same
-- as 'avatar' id=10 and 'WORDING_LOGOUT' id=19) is hidden=0 — internal,
-- not user-facing, but routable. The navbar is built from the `navbar`
-- table anyway, not by listing non-hidden pages, so flipping the flag
-- has zero impact on what users see in navigation.
--
-- Safe to re-run.
-- ============================================================

UPDATE `page`
   SET `hidden` = 0
 WHERE `url_id` IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');

-- ============================================================
-- VERIFICATION:
--
-- SELECT id, url_id, file_name, template, controller, hidden
--   FROM `page`
--  WHERE url_id IN ('WORDING_CAPTCHA_IMAGE', 'WORDING_CAPTCHA_FRAME');
--
-- Expected: 2 rows, template=0, controller=1, hidden=0.
-- ============================================================


-- ---------- migrate_chat_admin_panel.sql ----------
-- ============================================================
-- AstrX migration: in-chat Administrative-functions panel page
-- ============================================================
-- Registers the moderator admin panel page (file_name `chat_admin` →
-- ChatAdminController), reached from the chat toolbar's Admin button and gated
-- by CHAT_MODERATE inside the controller. No schema change — the panel reuses
-- `chat_presence` (the sessions view) and `chat_message.type` = 'broadcast'.
-- New migration file (never edit an applied one — the setup runner rejects an
-- applied migration whose checksum changed). Idempotent.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_CHAT_ADMIN', 1, 'chat_admin', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_CHAT_ADMIN';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_CHAT_ADMIN';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_CHAT_ADMIN';


-- ---------- migrate_chat_filters.sql ----------
-- Phase 4 — managed word + link filters (enforcement layer).
--
-- Distinct from the cosmetic WordCensor (config textarea that stars-out/blocks):
-- each row here is a literal pattern matched against the whole message (word) or
-- only within its http(s) URLs (link); on a hit the action fires — block the
-- post, or kick the poster. Staff are exempt unless apply_to_mods is set.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE page registration.
-- Independent of every other migration's order.

CREATE TABLE IF NOT EXISTS `chat_filters`
(
    `id`            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pattern`       VARCHAR(255) NOT NULL,
    `kind`          TINYINT      NOT NULL DEFAULT 0,   -- 0 word, 1 link
    `action`        TINYINT      NOT NULL DEFAULT 0,   -- 0 block, 1 kick
    `apply_to_mods` TINYINT      NOT NULL DEFAULT 0,   -- 0 mods exempt, 1 applies to mods too
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Register the admin management page (file_name admin_chat_filters →
-- AstrX\Controller\AdminChatFiltersController, template=1 site chrome).
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_CHAT_FILTERS', 1, 'admin_chat_filters', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_CHAT_FILTERS';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_CHAT_FILTERS';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_CHAT_FILTERS';


-- ---------- migrate_chat_parity_cde.sql ----------
-- ============================================================
-- AstrX migration: chat parity phases C/D/E
-- ============================================================
-- Adds the per-user "incognito" flag to chat_settings (hide from the roster).
-- The phase C/D/E CONFIG keys (announce_join_leave, image_embed, entry_password,
-- chat_enabled, disabled_message) live in Chat.config.php, not the DB.
-- New migration file (never edit an applied one). Idempotent.
--
-- NOTE: no `AFTER <col>` clause. Migrations run in alphabetical filename order,
-- and this file sorts BEFORE migrate_chat_profile.sql — so the columns that
-- file adds (e.g. hide_chatters) may not exist yet. Column position is
-- cosmetic; the app reads columns by name, so we just append.
-- ============================================================

ALTER TABLE `chat_settings`
    ADD COLUMN IF NOT EXISTS `incognito` TINYINT NOT NULL DEFAULT 0;


-- ---------- migrate_chat_profile.sql ----------
-- ============================================================
-- AstrX migration: chat profile expansion (le-chat parity, phase A)
-- ============================================================
-- Adds per-user profile fields to chat_settings (background colour, font
-- family, per-user sort direction, hide-chatters) and registers the chat Help
-- page. New migration file (never edit migrate_add_chat.sql — the setup runner
-- rejects an applied migration whose checksum changed). Idempotent.
-- ============================================================

-- ---- chat_settings: new per-user profile columns --------------------------
ALTER TABLE `chat_settings`
    ADD COLUMN IF NOT EXISTS `bg_color`      VARCHAR(16) NULL       AFTER `text_color`,
    ADD COLUMN IF NOT EXISTS `font_family`   VARCHAR(32) NULL       AFTER `bg_color`,
    ADD COLUMN IF NOT EXISTS `sort_dir`      TINYINT     NULL       AFTER `font_family`,
    ADD COLUMN IF NOT EXISTS `hide_chatters` TINYINT     NOT NULL DEFAULT 0 AFTER `sort_dir`;

-- ---- Chat Help page (templated, no navbar entry — reached via the chat toolbar)
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_CHAT_HELP', 1, 'chat_help', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_CHAT_HELP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_CHAT_HELP';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_CHAT_HELP';


-- ---------- migrate_chat_profile_tz.sql ----------
-- ============================================================
-- AstrX migration: per-user chat timezone + notes (profile enrichment)
-- ============================================================
-- Adds a per-user timezone (timestamps render in the viewer's zone) and a
-- personal notes scratchpad to chat_settings. New migration file (never edit an
-- applied one). Idempotent.
--
-- NOTE: no `AFTER <col>` clause — migrations run alphabetically and must not
-- depend on a column another migration adds. Column position is cosmetic.
-- ============================================================

ALTER TABLE `chat_settings`
    ADD COLUMN IF NOT EXISTS `timezone` VARCHAR(48) NULL,
    ADD COLUMN IF NOT EXISTS `notes`    TEXT        NULL;


-- ---------- migrate_feed.sql ----------
-- ============================================================
-- AstrX migration: Atom feed page (fix115)
-- Registers the /<locale>/feed.xml endpoint backed by FeedController.
-- Idempotent.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_FEED', 1, 'feed', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_FEED';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_FEED';

-- Crawlers should know about the feed — index=1, follow=1.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_FEED';

-- Verify:
--   SELECT * FROM page WHERE url_id = 'WORDING_FEED';


-- ---------- migrate_fix_banlist_ip_prefix.sql ----------
-- ============================================================
-- AstrX migration: fix banlist_ip.prefix_len overflow (IPv4 bans)
--
-- Bug: `banlist_ip.prefix_len` was TINYINT — signed, max 127. But an IPv4
-- address is stored as an IPv4-mapped IPv6 network (::ffff:a.b.c.d), so
-- BanlistRepository::parseCidr() reports a /128 prefix (a bare IPv4 /32 + 96).
-- 128 > 127, so EVERY IPv4 ban overflowed the column and the INSERT failed
-- silently (banCidr returned an error the callers treat as best-effort). Net
-- effect: kicked/banned guests were only nick-banned, never IP-banned, so they
-- could rejoin from the same IP by changing nickname.
--
-- Fix: widen to TINYINT UNSIGNED (0-255), which holds 128. Existing values
-- (0-127) are preserved. Idempotent — MODIFY to the same type is a no-op.
--
-- This is a framework-level banlist fix; it also repairs admin IPv4/`/32` bans,
-- not just chat kicks.
-- ============================================================

ALTER TABLE `banlist_ip` MODIFY COLUMN `prefix_len` TINYINT UNSIGNED NOT NULL;

-- ============================================================
-- VERIFICATION:
--   SHOW COLUMNS FROM `banlist_ip` LIKE 'prefix_len';   -- Type: tinyint(3) unsigned
-- ============================================================


-- ---------- migrate_fix_view.sql ----------
-- ============================================================
-- AstrX migration: bulletproof resolved_page view rebuild (fix122)
-- Resolves "Unknown column 'index' in 'SELECT'" from PageHandler.
--
-- Run with:
--   docker compose exec -T mariadb mysql -u user -ppassword content_manager \
--       < src/setup/migrate_fix_view.sql
--
-- This is the same as fix121 but with extra safety steps and explicit
-- error-on-failure. Idempotent. Safe to re-run.
-- ============================================================

-- 1. Make sure page.api_enabled exists (no-op if it already does).
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0;

-- 2. Drop the view unconditionally. We want a clean recreate, no merge
--    behavior. If the view doesn't exist, IF EXISTS prevents an error.
DROP VIEW IF EXISTS `resolved_page`;

-- 3. Recreate. Column names match PageHandler::getPage()'s SELECT exactly:
--    `id`, `url_id`, `i18n`, `file_name`, `template`, `controller`,
--    `hidden`, `comments`, `api_enabled`, `index`, `follow`, `title`,
--    `description`, `template_file_name`.
--    `index` and `follow` come from page_robots; `title`/`description`
--    from page_meta; `template_file_name` from the template table joined
--    via page_template.
CREATE VIEW `resolved_page` AS
SELECT p.id,
       p.url_id,
       p.i18n,
       p.file_name,
       p.template,
       p.controller,
       p.hidden,
       p.comments,
       p.api_enabled,
       COALESCE(pr.`index`, 1) AS `index`,
       COALESCE(pr.follow, 1) AS follow,
       COALESCE(pm.title, '') AS title,
       COALESCE(pm.description, '') AS description,
       COALESCE(t.file_name, '') AS template_file_name
FROM `page` p
         LEFT JOIN `page_robots`   pr ON pr.page_id = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id = p.id
         LEFT JOIN `page_template` pt ON pt.page_id = p.id
         LEFT JOIN `template`      t  ON t.id       = pt.template_id;

-- 4. Inline verification (will fail loudly if anything is wrong).
--    The SELECT below uses the exact column list PageHandler expects.
--    If this returns rows (or even "Empty set"), the view is healthy.
--    If it errors out, the view is STILL broken and the rebuild above
--    didn't take.
SELECT `id`, `url_id`, `i18n`, `file_name`, `template`, `controller`,
       `hidden`, `comments`, `api_enabled`,
       `index`, `follow`,
       `title`, `description`,
       `template_file_name`
  FROM `resolved_page`
 LIMIT 1;


-- ---------- migrate_js_browser.sql ----------
-- ============================================================
-- AstrX migration: JS browser namespace hardening (fix-js-browser)
-- Ensures /<locale>/js/ is a visible, template-less controller page.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_JS_APP', 1, 'js', 0, 1, 0, 0);

UPDATE `page`
   SET `file_name` = 'js',
       `template` = 0,
       `controller` = 1,
       `hidden` = 0,
       `comments` = 0
 WHERE `url_id` = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, 'JS browser', 'Experimental client-side browser runtime.'
  FROM `page`
 WHERE url_id = 'WORDING_JS_APP';

UPDATE `page_meta` pm
JOIN `page` p ON p.id = pm.page_id
   SET pm.title = CASE WHEN pm.title = '' THEN 'JS browser' ELSE pm.title END,
       pm.description = CASE WHEN pm.description = '' THEN 'Experimental client-side browser runtime.' ELSE pm.description END
 WHERE p.url_id = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_JS_APP';

UPDATE `page_robots` pr
JOIN `page` p ON p.id = pr.page_id
   SET pr.`index` = 0,
       pr.follow = 0
 WHERE p.url_id = 'WORDING_JS_APP';


-- ---------- migrate_js_spa.sql ----------
-- ============================================================
-- AstrX migration: experimental JS SPA page (fix117)
-- Registers /<locale>/js/ backed by JsController.
-- ============================================================

INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_JS_APP', 1, 'js', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_JS_APP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_JS_APP';

-- Search engines should NOT crawl the SPA — it's an experimental client
-- view of the same content that's already served the traditional way.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_JS_APP';


-- ---------- migrate_remove_captcha_test.sql ----------
-- Remove the leftover 'captcha-test' page: an unauthenticated, CSRF-less test
-- endpoint that was wired to the production captcha table. Idempotent — safe to
-- run repeatedly. After running this, delete the controller class file:
--   src/AstrX/Controller/CaptchaTestController.php
DELETE pc
  FROM `page_closure` pc
  JOIN `page` p ON (p.id = pc.ancestor OR p.id = pc.descendant)
 WHERE p.url_id = 'captcha-test';

DELETE FROM `page` WHERE url_id = 'captcha-test';


-- ---------- migrate_remove_seed_admin.sql ----------
-- Remove the legacy seeded Administrator account if it was created by an older
-- setup SQL file. The setup wizard now creates the first administrator from the
-- submitted setup form. This is intentionally narrow: it deletes only the exact
-- historical seed account/hash, not a real admin whose password was changed.

DELETE FROM `user`
WHERE username = 'Administrator'
  AND password = '$argon2id$v=19$m=65536,t=4,p=1$b2Z2cnVLM0pSMy9xUVVicw$6KUaczD3Y6rGl28q61y6YXxriNmGqKv2I6xucl8rcSE'
  AND type = 1
  AND verified = 1
  AND deleted = 0;


-- ---------- migrate_spa_api_enable.sql ----------
-- ============================================================
-- AstrX migration: enable API for SPA + safety-net view rebuild (fix120)
--
-- This re-delivers fix119's enablement and ALSO rebuilds the resolved_page
-- view in case the api_enabled column was missing from it (which would
-- cause $page->apiEnabled to always be NULL → /api/<slug> always 404).
--
-- Safe to re-run. Idempotent. No data loss.
-- ============================================================

-- 1. Ensure the column exists. NOTE: this is from migrate_api.sql (fix99);
--    we run it again here defensively in case the user has an older schema.
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0;

-- 2. Drop and recreate the resolved_page view so it includes api_enabled.
--    A view referencing a column that didn't exist when the view was
--    created will NOT pick up the column post-hoc — the view must be
--    rebuilt. DROP+CREATE is the simplest path.
DROP VIEW IF EXISTS `resolved_page`;
CREATE VIEW `resolved_page` AS
SELECT p.id,
       p.url_id,
       p.i18n,
       p.file_name,
       p.template,
       p.controller,
       p.hidden,
       p.comments,
       p.api_enabled,
       COALESCE(pr.`index`, 1) AS `index`,
       COALESCE(pr.follow, 1) AS follow,
       COALESCE(pm.title, '') AS title,
       COALESCE(pm.description, '') AS description,
       COALESCE(t.file_name, '') AS template_file_name
FROM `page` p
         LEFT JOIN `page_robots`   pr ON pr.page_id   = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id   = p.id
         LEFT JOIN `page_template` pt ON pt.page_id   = p.id
         LEFT JOIN `template`      t  ON t.id          = pt.template_id;

-- 3. Flip api_enabled=1 on the pages the SPA needs.
UPDATE `page`
   SET `api_enabled` = 1
 WHERE `url_id` IN (
       'WORDING_MAIN',
       'WORDING_USER_HOME',
       'WORDING_PROFILE',
       'WORDING_LOGIN',
       'WORDING_REGISTER',
       'WORDING_RECOVER'
 );

-- ============================================================
-- VERIFICATION (paste these into a separate mysql session):
--
-- -- (a) Schema check: does page.api_enabled exist?
-- SHOW COLUMNS FROM page LIKE 'api_enabled';
-- -- expected: one row with Type=tinyint(4), Default=0
--
-- -- (b) View check: does resolved_page include api_enabled?
-- SHOW COLUMNS FROM resolved_page LIKE 'api_enabled';
-- -- expected: one row
--
-- -- (c) Data check: which pages have api_enabled=1?
-- SELECT url_id, api_enabled FROM page WHERE api_enabled = 1 ORDER BY url_id;
-- -- expected: 6 rows (MAIN, USER_HOME, PROFILE, LOGIN, REGISTER, RECOVER)
-- ============================================================


-- ---------- migrate_themes.sql ----------
-- ============================================================
-- AstrX migration: theme system (fix95)
-- Run ONCE on an existing database. Safe to re-run (uses IF NOT EXISTS).
-- ============================================================

-- 1. User table: per-user theme preference. NULL = use global theme.
ALTER TABLE `user`
    ADD COLUMN IF NOT EXISTS `theme` VARCHAR(64) NULL DEFAULT NULL
    AFTER `deletion_mode`;

-- 2. Register the admin themes page (idempotent — uses INSERT IGNORE).
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_ADMIN_THEMES', 1, 'admin_themes', 1, 1, 0, 0);

-- 3. Closure self-reference for the new page (no parent — top-level admin page).
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_THEMES';

-- 4. Page meta (title + description come from the lang file via WORDING_*).
INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_THEMES';

-- 5. Robots: no-index, no-follow for admin pages.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_THEMES';


-- ---------- migrate_zz_repair_resolved_page.sql ----------
-- ============================================================
-- AstrX migration: final resolved_page shape repair.
--
-- Several older migrations rebuilt `resolved_page` with transitional
-- column names such as robots_index/meta_title. PageHandler expects the
-- canonical names below, especially `index` and `follow`.
--
-- This file is intentionally named migrate_zz_* so the setup wizard runs it
-- after older migrations that may recreate the view.
-- Safe to re-run.
-- ============================================================

ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `api_enabled` TINYINT NOT NULL DEFAULT 0
    AFTER `comments`;

-- R10-01 (HIGH): the canonical view MUST expose `module`. tables.sql is re-run
-- in full on every setup re-entry (runSQL in public/setup.php, NOT a
-- checksum-tracked migration), so this final rebuild is the LAST word on the
-- view's shape. migrate_module_page_ownership.sql adds `module` to the view on
-- first install, but is checksum-skipped on every re-run — so without carrying
-- `module` HERE, a re-run (the normal upgrade path) drops `module` from the
-- view and every DISABLED module's public pages silently fail OPEN
-- (PageHandler reads no module column -> '' -> ModulePageGuard treats it as
-- core/always-on -> shown). Guarantee the column exists, then carry it below.
ALTER TABLE `page`
    ADD COLUMN IF NOT EXISTS `module` VARCHAR(32) NOT NULL DEFAULT ''
    AFTER `api_enabled`;

DROP VIEW IF EXISTS `resolved_page`;
CREATE VIEW `resolved_page` AS
SELECT p.id,
       p.url_id,
       p.i18n,
       p.file_name,
       p.template,
       p.controller,
       p.hidden,
       p.comments,
       p.api_enabled,
       p.module,
       COALESCE(pr.`index`, 1) AS `index`,
       COALESCE(pr.follow, 1) AS follow,
       COALESCE(pm.title, '') AS title,
       COALESCE(pm.description, '') AS description,
       COALESCE(t.file_name, '') AS template_file_name
FROM `page` p
         LEFT JOIN `page_robots`   pr ON pr.page_id   = p.id
         LEFT JOIN `page_meta`     pm ON pm.page_id   = p.id
         LEFT JOIN `page_template` pt ON pt.page_id   = p.id
         LEFT JOIN `template`      t  ON t.id          = pt.template_id;

SELECT `id`, `url_id`, `i18n`, `file_name`, `template`, `controller`,
       `hidden`, `comments`, `api_enabled`, `module`, `index`, `follow`, `title`,
       `description`, `template_file_name`
  FROM `resolved_page`
 LIMIT 1;


-- ---------- migrate_zz_theme_nav_entry.sql ----------
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


-- ---------- migrate_zzz_admin_nav_consolidate.sql ----------
-- ============================================================
-- AstrX migration: consolidate the admin navbar into one group
-- ============================================================
-- An earlier chat migration created its own admin navbar pin, and the
-- theme-nav migration appends its entry to the LAST admin pin — so upgraded
-- databases could end up with a stray trailing admin group AND a duplicated
-- "Themes" entry living in it. A fresh install is already correct, so on a
-- clean database every statement below is a no-op.
--
-- This migration converges ANY admin navbar to the shipped shape: the
-- Dashboard pin, plus a single alpha-sorted group that holds every other admin
-- entry, with no duplicate entries and no empty pins.
--
-- FK chain (all ON DELETE CASCADE): navbar_entry_ids <- navbar_entry <-
-- navbar_internal / navbar_external. Deleting an id from navbar_entry_ids
-- removes the entry and its internal/external row in one shot.
--
-- Idempotent and destructive-safe: it only ever removes exact duplicates
-- (two entries pointing at the same page) and moves entries between pins.
-- ============================================================

SET @admin_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);

-- The Dashboard pin = the pin that holds the WORDING_ADMIN entry (kept as-is).
SET @dash_pin_id := (
    SELECT e.pin_id
      FROM `navbar_entry` e
      JOIN `navbar_pin` p ON p.id = e.pin_id
     WHERE p.navbar_id = @admin_navbar_id
       AND e.internal = 1 AND e.name = 'WORDING_ADMIN'
     ORDER BY e.pin_id
     LIMIT 1
);

-- The canonical group pin = the alpha-sorted (sort_mode = 0) admin pin with the
-- lowest id; fall back to the lowest non-dashboard admin pin if none is alpha.
SET @alpha_pin_id := (
    SELECT p.id FROM `navbar_pin` p
     WHERE p.navbar_id = @admin_navbar_id AND p.sort_mode = 0
     ORDER BY p.id
     LIMIT 1
);
SET @alpha_pin_id := COALESCE(@alpha_pin_id, (
    SELECT p.id FROM `navbar_pin` p
     WHERE p.navbar_id = @admin_navbar_id
       AND (@dash_pin_id IS NULL OR p.id <> @dash_pin_id)
     ORDER BY p.id
     LIMIT 1
));

-- 1) Remove duplicate internal admin entries (same page_id): keep the lowest id.
DELETE FROM `navbar_entry_ids`
 WHERE id IN (
    SELECT id FROM (
        SELECT ni.id AS id
          FROM `navbar_internal` ni
          JOIN `navbar_entry` e ON e.id = ni.id
          JOIN `navbar_pin`   p ON p.id = e.pin_id
         WHERE p.navbar_id = @admin_navbar_id
           AND ni.id > (
               SELECT MIN(ni2.id)
                 FROM `navbar_internal` ni2
                 JOIN `navbar_entry` e2 ON e2.id = ni2.id
                 JOIN `navbar_pin`   p2 ON p2.id = e2.pin_id
                WHERE p2.navbar_id = @admin_navbar_id
                  AND ni2.page_id = ni.page_id
           )
    ) AS dupes
 );

-- 2) Move every remaining admin entry EXCEPT the Dashboard onto the group pin.
UPDATE `navbar_entry` e
   JOIN `navbar_pin` p ON p.id = e.pin_id
   SET e.pin_id = @alpha_pin_id
 WHERE @alpha_pin_id IS NOT NULL
   AND p.navbar_id = @admin_navbar_id
   AND e.pin_id <> @alpha_pin_id
   AND NOT (e.internal = 1 AND e.name = 'WORDING_ADMIN');

-- 3) Drop any now-empty admin pins, keeping the Dashboard and the group pin.
DELETE FROM `navbar_pin`
 WHERE navbar_id = @admin_navbar_id
   AND (@dash_pin_id  IS NULL OR id <> @dash_pin_id)
   AND (@alpha_pin_id IS NULL OR id <> @alpha_pin_id)
   AND id NOT IN (SELECT DISTINCT pin_id FROM `navbar_entry`);

-- Verify:
--   SELECT e.id, e.pin_id, e.name FROM navbar_entry e
--     JOIN navbar_pin p ON p.id = e.pin_id JOIN navbar n ON n.id = p.navbar_id
--    WHERE n.name = 'admin' ORDER BY e.pin_id, e.sort_order, e.id;


-- ---------- migrate_zzz_chat_unhide.sql ----------
-- ============================================================
-- AstrX migration: unhide chat pages
--
-- Symptom: an admin viewing a chat page (e.g. the in-chat Admin panel or the
-- chat configuration page) sees the banner
--     "⚠ Admin view: this page is hidden from public visitors."
--
-- Cause: that page's row carries hidden=1 on a long-lived install. The
-- framework's ContentManager 404s a hidden page for non-admins AND shows
-- admins that banner (astrx.content/page_hidden):
--
--     $adminViewingHidden = $page->hidden && $gate->can(ADMIN_ACCESS);
--     if (!$adminViewingHidden && $page->hidden) { http_response_code(404); }
--
-- The chat pages are internal / gated by their own controllers, not public
-- navbar entries — the navbar is built from the `navbar` table, so `hidden`
-- has ZERO impact on navigation (same rationale as migrate_captcha_unhide.sql).
-- The correct value is hidden=0: routable, with the controller enforcing
-- access (CHAT_MODERATE / ADMIN_CONFIG_CHAT). A registration migration's
-- INSERT IGNORE cannot rewrite an existing row, so this UPDATE corrects it.
--
-- Named zzz_* so it runs after every page-registration migration. Idempotent,
-- safe to re-run (the guard skips rows already at 0).
-- ============================================================

UPDATE `page`
   SET `hidden` = 0
 WHERE `hidden` <> 0
   AND `url_id` IN (
       'WORDING_CHAT',
       'WORDING_CHAT_STREAM',
       'WORDING_CHAT_LOGIN',
       'WORDING_CHAT_WAIT',
       'WORDING_CHAT_USERS',
       'WORDING_CHAT_PM',
       'WORDING_CHAT_SETTINGS',
       'WORDING_CHAT_HELP',
       'WORDING_CHAT_ADMIN',
       'WORDING_ADMIN_CONFIG_CHAT',
       'WORDING_ADMIN_CHAT_FILTERS'
   );

-- ============================================================
-- VERIFICATION:
--   SELECT url_id, file_name, hidden FROM `page`
--    WHERE url_id LIKE '%CHAT%';
--   Expected: every chat page hidden=0.
-- ============================================================

-- ============================================================
-- Chat: image attachments (Phase 5) + report queue (#132)
-- Folded into the schema (this project ships no migration files).
-- ============================================================

-- Phase 5 — chat file attachments (images only; EXIF-stripped via GD re-encode).
--
-- Stores one row per attached image, linked to its chat_message. The file itself
-- lives on disk (configurable upload_dir) under a random stored_name; the row
-- carries a random unguessable `token` used by the serve route (?t=token) so the
-- on-disk name is never exposed and files can't be enumerated. ON DELETE CASCADE
-- ties an attachment's lifetime to its message (clean/purge/expiry drop the row).
--
-- Idempotent: CREATE TABLE IF NOT EXISTS + INSERT IGNORE page registration.

CREATE TABLE IF NOT EXISTS `chat_attachment`
(
    `id`          INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `message_id`  INT          NOT NULL,
    `token`       CHAR(32)     NOT NULL UNIQUE,
    `stored_name` VARCHAR(64)  NOT NULL,
    `mime`        VARCHAR(32)  NOT NULL,
    `byte_size`   INT          NOT NULL DEFAULT 0,
    `width`       INT          NOT NULL DEFAULT 0,
    `height`      INT          NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id),
    FOREIGN KEY (message_id) REFERENCES chat_message (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Serve route: file_name chat_file → AstrX\Controller\ChatFileController,
-- template=0 (raw bytes, no site chrome).
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('WORDING_CHAT_FILE', 1, 'chat_file', 0, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_CHAT_FILE';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_CHAT_FILE';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_CHAT_FILE';

-- #132 report → moderator queue.
CREATE TABLE IF NOT EXISTS `chat_report`
(
    `id`             INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `message_id`     INT         NOT NULL,
    `reporter_ident` VARCHAR(32) NOT NULL,
    `created_at`     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `resolved`       TINYINT     NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_report` (`message_id`, `reporter_ident`),
    INDEX `idx_resolved` (`resolved`),
    FOREIGN KEY (`message_id`) REFERENCES `chat_message` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


-- ============================================================
-- IMAGEBOARD MODULE
-- Boards are data rows (routed via a dispatcher page); threads and
-- posts hang off boards; images off posts. Per-board settings live
-- here (config objects are singletons). FKs to `user` use BINARY(16).
-- ============================================================

CREATE TABLE IF NOT EXISTS `board`
(
    `id`            INT              NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `slug`          VARCHAR(32)      NOT NULL UNIQUE,               -- URL segment, e.g. 'b'
    `title`         VARCHAR(128)     NOT NULL,
    `subtitle`      VARCHAR(255)     NOT NULL DEFAULT '',
    `description`   TEXT             NOT NULL,
    `banner`        VARCHAR(255)     NOT NULL DEFAULT '',           -- site-relative banner image path ('' = none)
    `rules`         VARCHAR(2000)    NOT NULL DEFAULT '',           -- per-board rules / info blurb ('' = none)
    `owner_user_id` BINARY(16)       NULL,                         -- per-board owner (granular mod)
    `active`        TINYINT          NOT NULL DEFAULT 1,
    `nsfw`          TINYINT          NOT NULL DEFAULT 0,
    `forced_anon`   TINYINT          NOT NULL DEFAULT 0,           -- disable names/tripcodes
    `bbcode`        TINYINT          NOT NULL DEFAULT 1,
    `flags_mode`    ENUM('off','user','geo') NOT NULL DEFAULT 'off',
    `poster_ids`    TINYINT          NOT NULL DEFAULT 0,           -- per-thread poster IDs on/off
    `lifecycle`     ENUM('ephemeral','archive','persistent') NOT NULL DEFAULT 'archive',
    `bump_limit`    SMALLINT UNSIGNED NOT NULL DEFAULT 300,
    `image_limit`   SMALLINT UNSIGNED NOT NULL DEFAULT 150,
    `thread_limit`  SMALLINT UNSIGNED NOT NULL DEFAULT 100,        -- max active threads before prune
    `max_post_len`  SMALLINT UNSIGNED NOT NULL DEFAULT 2000,
    `cooldown_secs` SMALLINT UNSIGNED NOT NULL DEFAULT 30,         -- per-poster post cooldown
    `max_replies`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,          -- thread auto-locks past this (0 = use global default)
    `post_seq`      INT UNSIGNED     NOT NULL DEFAULT 0,           -- per-board post counter (the `no`)
    `sort_order`    INT              NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `board_owner_fk` FOREIGN KEY (`owner_user_id`) REFERENCES `user` (`id`) ON DELETE SET NULL,
    INDEX `idx_board_active` (`active`, `sort_order`)
);

-- Per-board volunteer moderators (granular tier below global MOD/ADMIN).
CREATE TABLE IF NOT EXISTS `board_mod`
(
    `board_id`   INT        NOT NULL,
    `user_id`    BINARY(16) NOT NULL,
    `role`       ENUM('janitor','moderator') NOT NULL DEFAULT 'janitor',
    `created_at` TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`board_id`, `user_id`),
    CONSTRAINT `board_mod_board_fk` FOREIGN KEY (`board_id`) REFERENCES `board` (`id`) ON DELETE CASCADE,
    CONSTRAINT `board_mod_user_fk`  FOREIGN KEY (`user_id`)  REFERENCES `user`  (`id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `board_thread`
(
    `id`          INT               NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `board_id`    INT               NOT NULL,
    `subject`     VARCHAR(255)      NOT NULL DEFAULT '',           -- OP subject, mirrored for catalog
    `sticky`      TINYINT           NOT NULL DEFAULT 0,
    `locked`      TINYINT           NOT NULL DEFAULT 0,
    `cycle`       TINYINT           NOT NULL DEFAULT 0,            -- rolling thread
    `autosage`    TINYINT           NOT NULL DEFAULT 0,
    `archived`    TINYINT           NOT NULL DEFAULT 0,
    `reply_count` INT UNSIGNED      NOT NULL DEFAULT 0,
    `image_count` INT UNSIGNED      NOT NULL DEFAULT 0,
    `bump_time`   TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`  TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `board_thread_board_fk` FOREIGN KEY (`board_id`) REFERENCES `board` (`id`) ON DELETE CASCADE,
    INDEX `idx_thread_board_bump` (`board_id`, `archived`, `sticky`, `bump_time`),
    INDEX `idx_thread_board_created` (`board_id`, `created_at`)
);

CREATE TABLE IF NOT EXISTS `board_post`
(
    `id`             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `thread_id`      INT           NOT NULL,
    `board_id`       INT           NOT NULL,
    `no`             INT UNSIGNED  NOT NULL,                       -- per-board post number
    `is_op`          TINYINT       NOT NULL DEFAULT 0,
    `name`           VARCHAR(64)   NOT NULL DEFAULT '',
    `tripcode`       VARCHAR(64)   NOT NULL DEFAULT '',
    `capcode`        VARCHAR(32)   NOT NULL DEFAULT '',
    `poster_id`      VARCHAR(12)   NOT NULL DEFAULT '',
    `flag_code`      VARCHAR(16)   NOT NULL DEFAULT '',
    `subject`        VARCHAR(255)  NOT NULL DEFAULT '',
    `body_raw`       MEDIUMTEXT    NOT NULL,
    `body_html`      MEDIUMTEXT    NOT NULL,
    `user_id`        BINARY(16)    NULL,                           -- authenticated poster (nullable = anon)
    `ip`             VARBINARY(16) NULL,
    `poster_key`     CHAR(64)      NOT NULL DEFAULT '',            -- hashed key for poster IDs & post-history
    `delete_pw_hash` VARCHAR(255)  NOT NULL DEFAULT '',            -- poster self-delete password
    `sage`           TINYINT       NOT NULL DEFAULT 0,
    `banned`         TINYINT       NOT NULL DEFAULT 0,             -- "USER WAS BANNED FOR THIS POST"
    `verified`       TINYINT       NOT NULL DEFAULT 0,            -- verified-account poster badge
    `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `board_post_thread_fk` FOREIGN KEY (`thread_id`) REFERENCES `board_thread` (`id`) ON DELETE CASCADE,
    CONSTRAINT `board_post_board_fk`  FOREIGN KEY (`board_id`)  REFERENCES `board`        (`id`) ON DELETE CASCADE,
    CONSTRAINT `board_post_user_fk`   FOREIGN KEY (`user_id`)   REFERENCES `user`         (`id`) ON DELETE SET NULL,
    UNIQUE KEY `uq_board_no` (`board_id`, `no`),
    INDEX `idx_post_thread` (`thread_id`, `created_at`),
    INDEX `idx_post_thread_id` (`thread_id`, `id`),
    INDEX `idx_post_poster` (`board_id`, `poster_key`)
);

CREATE TABLE IF NOT EXISTS `board_image`
(
    `id`         INT               NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `post_id`    INT               NOT NULL,
    `token`      CHAR(32)          NOT NULL UNIQUE,                -- public serve token
    `full_name`  VARCHAR(64)       NOT NULL,                       -- on-disk full image
    `thumb_name` VARCHAR(64)       NOT NULL,                       -- on-disk thumbnail
    `mime`       VARCHAR(32)       NOT NULL,
    `byte_size`  INT UNSIGNED      NOT NULL,
    `width`      SMALLINT UNSIGNED NOT NULL,
    `height`     SMALLINT UNSIGNED NOT NULL,
    `thumb_w`    SMALLINT UNSIGNED NOT NULL,
    `thumb_h`    SMALLINT UNSIGNED NOT NULL,
    `ahash`      BIGINT UNSIGNED   NOT NULL DEFAULT 0,             -- perceptual average-hash (dedupe)
    `sha256`     CHAR(64)          NOT NULL DEFAULT '',            -- exact-match blocklist
    `orig_name`  VARCHAR(255)      NOT NULL DEFAULT '',
    `spoiler`    TINYINT           NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `board_image_post_fk` FOREIGN KEY (`post_id`) REFERENCES `board_post` (`id`) ON DELETE CASCADE,
    INDEX `idx_image_ahash` (`ahash`),
    INDEX `idx_image_sha`   (`sha256`)
);

CREATE TABLE IF NOT EXISTS `board_report`
(
    `id`             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `post_id`        INT          NOT NULL,
    `board_id`       INT          NOT NULL,
    `reporter_ident` VARCHAR(128) NOT NULL,
    `reason`         VARCHAR(255) NOT NULL DEFAULT '',
    `category`       ENUM('spam','illegal','offtopic','other') NOT NULL DEFAULT 'other',
    `resolved`       TINYINT      NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `board_report_post_fk` FOREIGN KEY (`post_id`) REFERENCES `board_post` (`id`) ON DELETE CASCADE,
    UNIQUE KEY `uq_board_report` (`post_id`, `reporter_ident`),
    INDEX `idx_report_open` (`board_id`, `resolved`)
);

-- Moderator image blocklist: reject known images by exact or perceptual hash.
CREATE TABLE IF NOT EXISTS `board_image_block`
(
    `id`         INT             NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `sha256`     CHAR(64)        NOT NULL DEFAULT '',
    `ahash`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `reason`     VARCHAR(255)    NOT NULL DEFAULT '',
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_block_sha`   (`sha256`),
    INDEX `idx_block_ahash` (`ahash`)
);

-- Board-scoped bans. board_id NULL = a global ban (every board). A ban may key
-- on an account (user_id), an IP / CIDR range (ip + prefix_len), or both. On a
-- Tor hidden service IP bans are near-useless (one proxy IP for everyone), so
-- account bans are the durable lever there; both are supported for the mixed
-- clearnet/onion deployments AstrX targets. reason is shown to the banned
-- poster; note is staff-private.
CREATE TABLE IF NOT EXISTS `board_ban`
(
    `id`         INT              NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `board_id`   INT              NULL,                        -- NULL = all boards
    `user_id`    BINARY(16)       NULL,                        -- account ban
    `ip`         VARBINARY(16)    NULL,                        -- network (packed) for IP/range ban
    `prefix_len` TINYINT UNSIGNED NOT NULL DEFAULT 128,        -- CIDR bits of `ip` that apply
    `reason`     VARCHAR(255)     NOT NULL DEFAULT '',         -- public, shown to the poster
    `note`       VARCHAR(255)     NOT NULL DEFAULT '',         -- staff-private
    `post_id`    INT              NULL,                        -- the offending post, for reference
    `created_by` BINARY(16)       NULL,                        -- staff who issued the ban
    `expires_at` TIMESTAMP        NULL,                        -- NULL = permanent
    `active`     TINYINT          NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `board_ban_board_fk` FOREIGN KEY (`board_id`) REFERENCES `board` (`id`) ON DELETE CASCADE,
    INDEX `idx_ban_ip`     (`ip`),
    INDEX `idx_ban_user`   (`user_id`),
    INDEX `idx_ban_lookup` (`board_id`, `active`)
);

-- Thread watching for authenticated users (no-JS "watched threads" page).
CREATE TABLE IF NOT EXISTS `board_watch`
(
    `user_id`    BINARY(16)   NOT NULL,
    `thread_id`  INT          NOT NULL,
    `last_seen`  INT UNSIGNED NOT NULL DEFAULT 0,                  -- last reply_count seen
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `thread_id`),
    CONSTRAINT `board_watch_user_fk`   FOREIGN KEY (`user_id`)   REFERENCES `user`         (`id`) ON DELETE CASCADE,
    CONSTRAINT `board_watch_thread_fk` FOREIGN KEY (`thread_id`) REFERENCES `board_thread` (`id`) ON DELETE CASCADE
);

-- Seed one example board.
INSERT IGNORE INTO `board` (`slug`, `title`, `subtitle`, `description`, `flags_mode`, `poster_ids`, `lifecycle`, `sort_order`)
VALUES ('b', 'Random', 'anything goes', 'The random board.', 'user', 1, 'archive', 0);

-- ============================================================
-- IMAGEBOARD PAGES (dispatcher + token file-serve) + public navbar
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_BOARD',      1, 'board',      1, 1, 0, 0),   -- dispatcher: index/catalog/thread + posting
    ('WORDING_BOARD_FILE', 1, 'board_file', 0, 1, 0, 0),   -- raw image serve by token (no shell)
    ('WORDING_BOARD_MOD',  1, 'board_mod',  1, 1, 0, 0),   -- moderation surface (unlisted; link-reached, access gated by BOARD_MODERATE)
    ('WORDING_BOARD_FEED', 1, 'board_feed', 0, 1, 0, 0);   -- Atom feed (raw XML; unlisted; link-reached)

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_BOARD','WORDING_BOARD_FILE','WORDING_BOARD_MOD','WORDING_BOARD_FEED');

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_BOARD','WORDING_BOARD_FILE','WORDING_BOARD_MOD','WORDING_BOARD_FEED');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id IN ('WORDING_BOARD','WORDING_BOARD_FEED');
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id IN ('WORDING_BOARD_FILE','WORDING_BOARD_MOD');

-- The board dispatcher is API-enabled: /api/board/<slug>[/thread/<id>|/catalog]
-- returns the same structured data (SHARED context) as a JSON envelope.
UPDATE `page` SET `api_enabled` = 1 WHERE `url_id` = 'WORDING_BOARD';

-- Public navbar entry for the board index (mirrors the chat entry).
SET @board_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_BOARD' LIMIT 1);
SET @board_pub_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @board_pub_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @board_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_board_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @board_page_id AND e.pin_id = @board_pub_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @board_page_id IS NOT NULL AND @board_pub_pin_id IS NOT NULL AND @existing_board_nav IS NULL;
SET @board_nav_id := COALESCE(@existing_board_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @board_nav_id, @board_pub_pin_id, 1, 'WORDING_BOARD', 1, 1, 0
 WHERE @board_page_id IS NOT NULL AND @board_pub_pin_id IS NOT NULL AND @board_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @board_nav_id, @board_page_id
 WHERE @board_page_id IS NOT NULL AND @board_nav_id IS NOT NULL;

-- ============================================================
-- IMAGEBOARD DISCOVERY PAGES (overboard + search; hidden, link-reached)
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_BOARD_OVERBOARD', 1, 'board_overboard', 1, 1, 0, 0),   -- overboard: newest threads across all boards (unlisted; link-reached)
    ('WORDING_BOARD_SEARCH',    1, 'board_search',    1, 1, 0, 0);   -- post/thread search (no-JS GET form; unlisted; link-reached)

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id IN ('WORDING_BOARD_OVERBOARD','WORDING_BOARD_SEARCH');

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id IN ('WORDING_BOARD_OVERBOARD','WORDING_BOARD_SEARCH');

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id IN ('WORDING_BOARD_OVERBOARD','WORDING_BOARD_SEARCH');

-- ============================================================
-- SITE-WIDE SEARCH PAGE (news + pages + comments + board posts) + public navbar
-- Slug WORDING_SEARCH ('search'); file_name 'site_search' resolves to
-- SiteSearchController via the reflection router. Public (gated by NEWS_VIEW).
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_SEARCH', 1, 'site_search', 1, 1, 0, 0);   -- global search (no-JS GET form)

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_SEARCH';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_SEARCH';

INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 1, 1 FROM `page` WHERE url_id = 'WORDING_SEARCH';

-- Public navbar entry for the search page (mirrors the board/chat entries).
SET @search_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_SEARCH' LIMIT 1);
SET @search_pub_navbar_id := (SELECT id FROM `navbar` WHERE name = 'public' LIMIT 1);
SET @search_pub_pin_id := (
    SELECT id FROM `navbar_pin`
     WHERE navbar_id = @search_pub_navbar_id
     ORDER BY sort_order ASC, id ASC LIMIT 1
);
SET @existing_search_nav := (
    SELECT ni.id FROM `navbar_internal` ni
      JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @search_page_id AND e.pin_id = @search_pub_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL
 WHERE @search_page_id IS NOT NULL AND @search_pub_pin_id IS NOT NULL AND @existing_search_nav IS NULL;
SET @search_nav_id := COALESCE(@existing_search_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @search_nav_id, @search_pub_pin_id, 1, 'WORDING_SEARCH', 1, 1, 0
 WHERE @search_page_id IS NOT NULL AND @search_pub_pin_id IS NOT NULL AND @search_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @search_nav_id, @search_page_id
 WHERE @search_page_id IS NOT NULL AND @search_nav_id IS NOT NULL;

-- ============================================================
-- IMAGEBOARD ADMIN PAGE (global config + board overview) + admin navbar
-- The board overview is folded into the config page — one admin surface.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_CONFIG_IMAGEBOARD',1, 'admin_config_imageboard', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';
-- Child of the admin root so its template resolves under admin/.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD';

-- Admin navbar entries (admin nav, last pin = the alpha-sorted group).
SET @admin_ib_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_ib_pin_id := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_ib_navbar_id
     ORDER BY sort_order DESC, id DESC LIMIT 1
);
-- WORDING_ADMIN_CONFIG_IMAGEBOARD
SET @ib_cfg_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_CONFIG_IMAGEBOARD' LIMIT 1);
SET @existing_ib_cfg_nav := (
    SELECT ni.id FROM `navbar_internal` ni JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @ib_cfg_page_id AND e.pin_id = @admin_ib_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @ib_cfg_page_id IS NOT NULL AND @admin_ib_pin_id IS NOT NULL AND @existing_ib_cfg_nav IS NULL;
SET @ib_cfg_nav_id := COALESCE(@existing_ib_cfg_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @ib_cfg_nav_id, @admin_ib_pin_id, 1, 'WORDING_ADMIN_CONFIG_IMAGEBOARD', 1, 1, 0
 WHERE @ib_cfg_page_id IS NOT NULL AND @admin_ib_pin_id IS NOT NULL AND @ib_cfg_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @ib_cfg_nav_id, @ib_cfg_page_id
 WHERE @ib_cfg_page_id IS NOT NULL AND @ib_cfg_nav_id IS NOT NULL;

-- ============================================================
-- SEARCH INDEX (on-demand FULLTEXT crawl of news/pages/comments/board posts)
-- Populated by tools/search_index.php / the admin "Search index" page — never
-- on write. SiteSearchService queries this table (MATCH ... AGAINST BOOLEAN)
-- and merges a live LIKE fallback for content newer than the last crawl.
-- MariaDB supports FULLTEXT on InnoDB, so the engine stays InnoDB throughout.
-- ============================================================
CREATE TABLE IF NOT EXISTS `search_index` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `doc_type`   VARCHAR(16)  NOT NULL,                       -- news|pages|comments|board
    `ref_id`     INT          NOT NULL,                       -- source row primary key
    `title`      VARCHAR(255) NOT NULL DEFAULT '',
    `body`       MEDIUMTEXT   NOT NULL,
    `url`        VARCHAR(512) NOT NULL DEFAULT '',
    `doc_time`   INT UNSIGNED NOT NULL DEFAULT 0,             -- source unix time (0 = pages)
    `indexed_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_doc` (`doc_type`, `ref_id`),
    FULLTEXT KEY `ft_body` (`title`, `body`),
    KEY `idx_indexed_at` (`indexed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-row job/status table (id is always 1). Tracks the crawl lifecycle so
-- the admin button, the CLI and the cron path all share one view of state.
CREATE TABLE IF NOT EXISTS `search_index_job` (
    `id`           TINYINT UNSIGNED NOT NULL,
    `status`       ENUM('idle','requested','running') NOT NULL DEFAULT 'idle',
    `doc_count`    INT NOT NULL DEFAULT 0,
    `requested_at` TIMESTAMP NULL DEFAULT NULL,
    `started_at`   TIMESTAMP NULL DEFAULT NULL,
    `finished_at`  TIMESTAMP NULL DEFAULT NULL,
    `message`      VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `search_index_job` (`id`, `status`, `doc_count`, `message`)
VALUES (1, 'idle', 0, '');

-- ============================================================
-- SEARCH INDEX ADMIN PAGE (rebuild controls) + admin navbar
-- file_name 'admin_search' resolves to AdminSearchController; child of the
-- admin root so its template lives under admin/admin_search.html.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_SEARCH', 1, 'admin_search', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_SEARCH';
-- Child of the admin root so its template resolves under admin/.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_SEARCH';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_SEARCH';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_SEARCH';

-- Admin navbar entry (admin nav, last pin = the alpha-sorted group).
SET @admin_search_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_search_pin_id := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_search_navbar_id
     ORDER BY sort_order DESC, id DESC LIMIT 1
);
SET @admin_search_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_SEARCH' LIMIT 1);
SET @existing_admin_search_nav := (
    SELECT ni.id FROM `navbar_internal` ni JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @admin_search_page_id AND e.pin_id = @admin_search_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @admin_search_page_id IS NOT NULL AND @admin_search_pin_id IS NOT NULL AND @existing_admin_search_nav IS NULL;
SET @admin_search_nav_id := COALESCE(@existing_admin_search_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @admin_search_nav_id, @admin_search_pin_id, 1, 'WORDING_ADMIN_SEARCH', 1, 1, 0
 WHERE @admin_search_page_id IS NOT NULL AND @admin_search_pin_id IS NOT NULL AND @admin_search_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @admin_search_nav_id, @admin_search_page_id
 WHERE @admin_search_page_id IS NOT NULL AND @admin_search_nav_id IS NOT NULL;

-- ============================================================
-- BOT-TRAP (honeypot labyrinth) PAGE + hit log
-- Slug WORDING_TRAP ('trap'); file_name 'bot_trap' resolves to BotTrapController
-- via the reflection router (str_replace('_','',ucwords('bot_trap','_')).'Controller').
-- template=0 (the controller emits a raw HTML maze and exit()s), controller=1.
--
-- hidden=0 (NOT 1) IS DELIBERATE. A hidden page is swapped to the 404 error page
-- for non-admins BEFORE its controller runs (see the WORDING_CAPTCHA_* note near
-- the top of this file: `if (!$adminViewingHidden && $page->hidden) { 404 }`), so
-- hidden=1 would stop the trap from EVER executing for a bot — the whole point.
-- It is kept out of navigation by adding NO navbar_entry (the navbar is built
-- from the `navbar` table, not by listing non-hidden pages) and out of honest
-- crawlers by robots.txt (Disallow: /*/trap). Reached only by the hidden footer
-- honeypot link + bots that ignore robots.txt. The controller is self-gated
-- (config `enabled`, default OFF → bare 404) and enforces its own behaviour.
-- Safe to re-run.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_TRAP', 1, 'bot_trap', 1, 1, 0, 0);   -- honeypot maze (site-styled: template=1 → default shell + bot_trap.html)

-- Existing installs seeded before the restyle had template=0 (raw HTML). Flip
-- them so the trap renders through the normal site shell (theme + navbars) via
-- the bot_trap.html content template. Idempotent — safe to re-run.
UPDATE `page` SET `template` = 1 WHERE `url_id` = 'WORDING_TRAP' AND `template` = 0;

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_TRAP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_TRAP';

-- noindex, nofollow — belt-and-braces with the controller's X-Robots-Tag header.
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_TRAP';

-- Bot-trap hit log. TOR-SAFE: `ident` is a sha256 hex digest of the session id
-- (or REMOTE_ADDR fallback) — a RAW IP IS NEVER STORED. Free-form strings are
-- truncated to their column widths by BotTrapLogRepository before insert.
CREATE TABLE IF NOT EXISTS `bot_trap_log` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `path`       VARCHAR(255) NOT NULL DEFAULT '',
    `user_agent` VARCHAR(255) NOT NULL DEFAULT '',
    `referer`    VARCHAR(255) NOT NULL DEFAULT '',
    `ident`      CHAR(64)     NOT NULL DEFAULT '',       -- sha256(session id | REMOTE_ADDR)
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- BOT-TRAP ADMIN PAGE (log viewer + config status) + admin navbar
-- file_name 'admin_trap' resolves to AdminTrapController; child of the admin
-- root so its template lives under admin/admin_trap.html. Mirrors the
-- WORDING_ADMIN_SEARCH seed above.
-- ============================================================
INSERT IGNORE INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
    ('WORDING_ADMIN_TRAP', 1, 'admin_trap', 1, 1, 0, 0);

INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT id, id FROM `page` WHERE url_id = 'WORDING_ADMIN_TRAP';
-- Child of the admin root so its template resolves under admin/ and it inherits
-- the ADMIN_ACCESS guard.
INSERT IGNORE INTO `page_closure` (ancestor, descendant)
SELECT a.id, d.id FROM `page` a, `page` d
 WHERE a.url_id = 'WORDING_ADMIN' AND d.url_id = 'WORDING_ADMIN_TRAP';

INSERT IGNORE INTO `page_meta` (page_id, title, description)
SELECT id, '', '' FROM `page` WHERE url_id = 'WORDING_ADMIN_TRAP';
INSERT IGNORE INTO `page_robots` (page_id, `index`, follow)
SELECT id, 0, 0 FROM `page` WHERE url_id = 'WORDING_ADMIN_TRAP';

-- Admin navbar entry (admin nav, last pin = the alpha-sorted group).
SET @admin_trap_navbar_id := (SELECT id FROM `navbar` WHERE name = 'admin' LIMIT 1);
SET @admin_trap_pin_id := (
    SELECT id FROM `navbar_pin` WHERE navbar_id = @admin_trap_navbar_id
     ORDER BY sort_order DESC, id DESC LIMIT 1
);
SET @admin_trap_page_id := (SELECT id FROM `page` WHERE url_id = 'WORDING_ADMIN_TRAP' LIMIT 1);
SET @existing_admin_trap_nav := (
    SELECT ni.id FROM `navbar_internal` ni JOIN `navbar_entry` e ON e.id = ni.id
     WHERE ni.page_id = @admin_trap_page_id AND e.pin_id = @admin_trap_pin_id LIMIT 1
);
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL WHERE @admin_trap_page_id IS NOT NULL AND @admin_trap_pin_id IS NOT NULL AND @existing_admin_trap_nav IS NULL;
SET @admin_trap_nav_id := COALESCE(@existing_admin_trap_nav, LAST_INSERT_ID());
INSERT IGNORE INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
SELECT @admin_trap_nav_id, @admin_trap_pin_id, 1, 'WORDING_ADMIN_TRAP', 1, 1, 0
 WHERE @admin_trap_page_id IS NOT NULL AND @admin_trap_pin_id IS NOT NULL AND @admin_trap_nav_id IS NOT NULL;
INSERT IGNORE INTO `navbar_internal` (id, page_id)
SELECT @admin_trap_nav_id, @admin_trap_page_id
 WHERE @admin_trap_page_id IS NOT NULL AND @admin_trap_nav_id IS NOT NULL;
