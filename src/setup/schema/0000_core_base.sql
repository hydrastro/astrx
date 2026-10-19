-- Core base schema — the first of the ordered pieces the installer applies on
-- every run (src/setup/schema/NNNN_*.sql plus each installed module's
-- resources/sql/schema/NNNN_*.sql, sorted by file name; see
-- AstrX\Database\SchemaFiles). Webmail's table, pages and nav entries live in
-- the Webmail module's 0005_webmail_base.sql; the folded-in former migrations
-- each follow as their own piece.
--
-- NOTE: no `USE <db>;` here on purpose. The installers connect with a DSN already
-- scoped to the configured database (mysql:...;dbname=...), and a hardcoded `USE
-- content_manager` would OVERRIDE that — silently writing into `content_manager`
-- (or aborting if it doesn't exist) whenever the operator chose a different DB name.
-- If you run a piece by hand, select the target DB first: `mysql <db> < 0000_core_base.sql`.

-- Force the (connection-default) database to utf8mb4 BEFORE any CREATE TABLE, so
-- every table below inherits it without needing an explicit per-table charset.
-- The `--create-db` installer path already creates the database as utf8mb4, but a
-- hand-created database left on the server's default (e.g. latin1) would otherwise
-- silently reject 4-byte input (emoji, many CJK/scripts) — a data-loss/█-mojibake
-- bug on exactly the international content a Tor CMS carries. Idempotent.
ALTER DATABASE CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci;


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
    -- R11: natural key so re-running the schema (setup re-entry runs it in full,
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
    -- seed idempotent when the schema is re-run on setup re-entry.
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
    `replace_at`  INT UNSIGNED NULL DEFAULT NULL,
    -- The two columns SecureSessionHandler::gc() deletes by. Without them each
    -- GC pass is a full table scan holding write locks, and GC fires on ~1% of
    -- session_start() calls. (migrate_zzzz_indexes_and_content_fk.sql adds these
    -- to installs that already exist — this CREATE only runs on a fresh one.)
    INDEX `idx_session_timestamp`  (`timestamp`),
    INDEX `idx_session_replace_at` (`replace_at`)
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
    -- username / email / mailbox are each declared UNIQUE above, which already
    -- builds a unique B-tree on the column. The plain INDEX that used to sit
    -- here as well was a second copy of the same tree: extra write cost on every
    -- INSERT and UPDATE, and never chosen by the planner over the unique one.
    -- (migrate_zzzz_indexes_and_content_fk.sql drops them where they exist.)
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
    `hidden`     TINYINT     NOT NULL DEFAULT 0,
    -- The front page's own query: WHERE hidden = 0 ORDER BY created_at DESC,
    -- id DESC LIMIT n. Covers the filter and both sort keys, so the LIMIT stops
    -- early instead of scanning and filesorting the whole table every render.
    INDEX `idx_news_hidden_created` (`hidden`, `created_at`, `id`)
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


-- Nick bans (mirrors banlist_email/banlist_ip). The chat bans by nick, but the
-- banlist and its admin page are core, so the table is too.
CREATE TABLE IF NOT EXISTS `banlist_nick`
(
    `ban_id` INT         NOT NULL PRIMARY KEY,
    `nick`   VARCHAR(64) NOT NULL,
    FOREIGN KEY (ban_id) REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_nick (nick)
) ENGINE=InnoDB;

-- ============================================================
-- SITE CONFIG
-- ============================================================

CREATE TABLE `site_config`
(
    `key`   VARCHAR(64) NOT NULL PRIMARY KEY,
    `value` TEXT        NOT NULL DEFAULT ''
);


-- ============================================================
-- DIAGNOSTICS: per-group visibility + level overrides
-- ============================================================

CREATE TABLE `diagnostic_visibility`
(
    `code`       VARCHAR(128) NOT NULL,
    `group_name` VARCHAR(32)  NOT NULL,
    -- No separate index on `code`: it is the LEADING column of this primary key,
    -- so the PK already serves every lookup a `code`-only index would.
    PRIMARY KEY (`code`, `group_name`)
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
--                             27  admin_audit_log
--                             28  admin_themes
-- (webmail + admin_config_webmail: the Webmail module's 0005_webmail_base.sql)
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
    ('WORDING_ADMIN_AUDIT_LOG',         1, 'admin_audit_log',        1, 1, 0, 0),  -- id=27
    ('WORDING_ADMIN_THEMES',            1, 'admin_themes',           1, 1, 0, 0);  -- id=28


INSERT INTO `page_robots` (page_id, `index`, follow)
VALUES
    (1,1,1),(2,0,0),(3,1,1),(4,1,1),(5,1,1),(6,0,0),(7,0,0),(8,0,0),(9,1,1),
    (10,0,0),(11,0,0),(12,0,0),(13,0,0),(14,0,0),(15,0,0),(16,0,0),(17,0,0),(18,0,0),(19,0,0),
    (20,0,0),(21,0,0),(22,0,0),(23,0,0),(24,0,0),(25,0,0),(26,0,0),
    (27,0,0),(28,0,0);


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
    (27, 'Admin — Audit Log',           'History of all admin actions.'),
    (28, 'Admin — Themes',              'Choose the global site theme.');


INSERT INTO `page_closure` (ancestor, descendant)
VALUES
    -- Self-references (every page is its own ancestor at depth 0)
    (1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),(8,8),(9,9),(10,10),
    (11,11),(12,12),(13,13),(14,14),(15,15),(16,16),(17,17),(18,18),(19,19),
    (20,20),(21,21),(22,22),(23,23),(24,24),(25,25),(26,26),
    (27,27),(28,28),
    -- User section children (9 is ancestor)
    (9,3),(9,4),(9,5),(9,6),(9,7),(9,8),(9,19),
    -- Admin section children (18 is ancestor)
    (18,11),(18,12),(18,13),(18,14),(18,15),(18,16),(18,17),
    (18,20),(18,21),(18,22),(18,23),(18,24),(18,25),(18,26),
    (18,27),(18,28);


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
    ('Mail',                0), ('Audit',               0), ('Log',                 0);


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
    (27,12),(27,13),(27,29),(27,30);


-- ----------------------------------------------------------
-- Navbars
-- ----------------------------------------------------------

INSERT IGNORE INTO `navbar` (name) VALUES ('public'), ('user'), ('admin');

-- R11: navbar_pin has NO natural UNIQUE key — two pins in one navbar can
-- legitimately share a (navbar_id, sort_order) position (the admin editor's
-- addPin takes a caller-supplied sort_order, default 0), so a UNIQUE would
-- regress navbar editing. Instead seed the six pins ONLY when the table is
-- empty, so re-running the schema (setup re-entry) can't duplicate them while
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

-- 24 navbar entries total. GUARDED with WHERE NOT EXISTS (like the navbar_pin seed
-- above) so re-applying the schema on an installer re-run can't leak 24 fresh orphan
-- allocator rows each time. On a fresh (empty) table this inserts ids 1..24 exactly
-- as the old bare VALUES did; on re-apply it inserts nothing.
INSERT INTO `navbar_entry_ids` (id)
SELECT NULL FROM (
              SELECT 1 UNION ALL SELECT 2  UNION ALL SELECT 3  UNION ALL SELECT 4
    UNION ALL SELECT 5  UNION ALL SELECT 6  UNION ALL SELECT 7  UNION ALL SELECT 8
    UNION ALL SELECT 9  UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12
    UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15 UNION ALL SELECT 16
    UNION ALL SELECT 17 UNION ALL SELECT 18 UNION ALL SELECT 19 UNION ALL SELECT 20
    UNION ALL SELECT 21 UNION ALL SELECT 22
) AS `seq`
WHERE NOT EXISTS (SELECT 1 FROM `navbar_entry_ids`);

INSERT INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
VALUES
    -- Public navbar
    (1,  1, 1, 'WORDING_HOME',                   1, 1, 0),
    (2,  1, 1, 'WORDING_USER',                   1, 1, 100),  -- "User Area" sorts LAST in the public pin (endcap), so content + transparency links group ahead of it
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
    -- Admin navbar — Audit Log and Themes (pin 6, alpha-sorted)
    (21, 6, 1, 'WORDING_ADMIN_AUDIT_LOG',        1, 1, 0),
    (22, 6, 1, 'WORDING_ADMIN_THEMES',           1, 1, 0);

INSERT INTO `navbar_internal` (id, page_id)
VALUES
    -- Entries with known stable page IDs
    (1,1),(2,9),(5,8),(6,6),(7,7),(8,19),
    (9,18),(10,14),(11,12),(12,17),(13,11),(14,13),(15,16),(16,15),
    (17,20),(18,21),(19,24),(20,26),
    -- Audit Log, Themes (page ids 27, 28)
    (21,27),(22,28);

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
