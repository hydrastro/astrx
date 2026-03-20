USE content_manager;

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
    `hidden`     TINYINT     NOT NULL DEFAULT 0,
    `comments`   TINYINT     NOT NULL DEFAULT 0
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
    `i18n`    TINYINT     NOT NULL DEFAULT 0
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
    `name` VARCHAR(64) NOT NULL
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
    `id`        VARCHAR(128) NOT NULL PRIMARY KEY,
    `timestamp` INT UNSIGNED NOT NULL,
    `data`      MEDIUMBLOB   NOT NULL DEFAULT ''
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
    `verified`         TINYINT      NOT NULL DEFAULT 0,
    `avatar`           TINYINT      NOT NULL DEFAULT 0,
    `deleted`          TINYINT      NOT NULL DEFAULT 0,
    `token_hash`       VARCHAR(255) NULL,
    `token_type`       TINYINT      NULL,
    `token_used`       TINYINT      NOT NULL DEFAULT 0,
    `token_expires_at` TIMESTAMP    NULL,
    INDEX idx_username (username),
    INDEX idx_email    (email),
    INDEX idx_mailbox  (mailbox),
    INDEX idx_deleted  (deleted)
);


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
--
-- Route/round definitions (penalty schedules) live in Banlist.config.php,
-- edited via the admin config UI. They are compile-time configuration.
--
-- ban_route is a VARCHAR(64) key matching BanlistRepository::ROUTE_* constants
-- (e.g. 'permanent', 'bad_comment', 'failed_login'). No FK to a route table —
-- the route definition lives in PHP config, not in the DB.
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
-- DATA INSERTIONS
-- ============================================================

-- ----------------------------------------------------------
-- Pages
--
-- ID map:
--   1  main             9  user (section root)
--   2  error           10  avatar
--   3  login           11  admin_banlist
--   4  register        12  admin_comments
--   5  recover         13  admin_navbar
--   6  profile         14  admin_news
--   7  user_settings   15  admin_notes
--   8  user_home       16  admin_pages
--                      17  admin_users
--                      18  admin (section root)
--                      19  logout
--                      20  admin_config_system
--                      21  admin_config_access
--                      22  admin_config_content
--                      23  admin_config_comments
--                      24  admin_config_captcha
--                      25  admin_config_users
--                      26  admin_config_mail
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
    ('WORDING_ADMIN_CONFIG_MAIL',       1, 'admin_config_mail',      1, 1, 0, 0);  -- id=26


INSERT INTO `page_robots` (page_id, `index`, follow)
VALUES
    (1,1,1),(2,0,0),(3,1,1),(4,1,1),(5,1,1),(6,0,0),(7,0,0),(8,0,0),(9,1,1),
    (10,0,0),(11,0,0),(12,0,0),(13,0,0),(14,0,0),(15,0,0),(16,0,0),(17,0,0),(18,0,0),(19,0,0),
    (20,0,0),(21,0,0),(22,0,0),(23,0,0),(24,0,0),(25,0,0),(26,0,0);


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
    (26, 'Config — Mail',               'Edit mail configuration.');


INSERT INTO `page_closure` (ancestor, descendant)
VALUES
    -- Self-references
    (1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),(8,8),(9,9),(10,10),
    (11,11),(12,12),(13,13),(14,14),(15,15),(16,16),(17,17),(18,18),(19,19),
    (20,20),(21,21),(22,22),(23,23),(24,24),(25,25),(26,26),
    -- User section children (9 is ancestor)
    (9,3),(9,4),(9,5),(9,6),(9,7),(9,8),(9,19),
    -- Admin section children (18 is ancestor)
    (18,11),(18,12),(18,13),(18,14),(18,15),(18,16),(18,17),
    (18,20),(18,21),(18,22),(18,23),(18,24),(18,25),(18,26);


INSERT INTO `keyword` (keyword, i18n)
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
    ('Mail',                0);


INSERT INTO `page_keyword` (page_id, keyword_id)
VALUES
    (1,1),(1,2),(1,7),(3,5),(3,3),(4,6),(4,3),(4,8),(4,9),(5,10),(5,11),(5,3),(5,1),
    (6,3),(6,4),(7,3),(7,8),(7,14),(9,3),(9,8),(18,12),(18,13),(18,14),
    (11,12),(11,13),(11,14),(11,15),(12,12),(12,13),(12,14),(12,16),
    (13,12),(13,13),(13,14),(13,17),(14,12),(14,13),(14,14),(14,18),
    (15,12),(15,13),(15,14),(15,19),(16,12),(16,13),(16,14),(16,20),
    (17,12),(17,13),(17,14),(17,21),
    -- Config pages share admin + config keywords
    (20,12),(20,13),(20,22),(20,23),(21,12),(21,13),(21,22),(21,24),(21,25),
    (22,12),(22,13),(22,22),(22,26),(23,12),(23,13),(23,22),(23,16),
    (24,12),(24,13),(24,22),(24,27),(25,12),(25,13),(25,22),(25,21),
    (26,12),(26,13),(26,22),(26,28);


-- ----------------------------------------------------------
-- Navbars
-- ----------------------------------------------------------

INSERT INTO `navbar` (name) VALUES ('public'), ('user'), ('admin');

INSERT INTO `navbar_pin` (navbar_id, sort_order, sort_mode)
VALUES
    (1, 0, 1),  -- id=1  public — custom
    (2, 0, 1),  -- id=2  user first pin (custom: User Home)
    (2, 1, 0),  -- id=3  user middle pin (alpha: Profile, Settings)
    (2, 2, 1),  -- id=4  user last pin (custom: Logout)
    (3, 0, 1),  -- id=5  admin: Dashboard (custom, single entry)
    (3, 1, 0);  -- id=6  admin: everything else (alpha-sorted, one group)

INSERT INTO `navbar_entry_ids` ()
VALUES (),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),(),();

INSERT INTO `navbar_entry` (id, pin_id, internal, name, i18n, active, sort_order)
VALUES
    -- Public navbar
    (1,  1, 1, 'WORDING_HOME',                  1, 1, 0),
    (2,  1, 1, 'WORDING_USER',                  1, 1, 1),
    (3,  1, 0, 'Test',                           0, 0, 2),
    (4,  1, 0, 'Ext',                            0, 0, 3),
    -- User navbar
    (5,  2, 1, 'WORDING_USER_HOME',             1, 1, 0),
    (6,  3, 1, 'WORDING_PROFILE',               1, 1, 0),
    (7,  3, 1, 'WORDING_SETTINGS',              1, 1, 0),
    (8,  4, 1, 'WORDING_LOGOUT',                1, 1, 0),
    -- Admin navbar — Dashboard (pin 5, custom)
    (9,  5, 1, 'WORDING_ADMIN',                 1, 1, 0),
    -- Admin navbar — all other pages (pin 6, alpha-sorted)
    (10, 6, 1, 'WORDING_ADMIN_NEWS',            1, 1, 0),
    (11, 6, 1, 'WORDING_ADMIN_COMMENTS',        1, 1, 0),
    (12, 6, 1, 'WORDING_ADMIN_USERS',           1, 1, 0),
    (13, 6, 1, 'WORDING_ADMIN_BANLIST',         1, 1, 0),
    (14, 6, 1, 'WORDING_ADMIN_NAVBAR',          1, 1, 0),
    (15, 6, 1, 'WORDING_ADMIN_PAGES',           1, 1, 0),
    (16, 6, 1, 'WORDING_ADMIN_NOTES',           1, 1, 0),
    (17, 6, 1, 'WORDING_ADMIN_CONFIG_SYSTEM',   1, 1, 0),
    (18, 6, 1, 'WORDING_ADMIN_CONFIG_ACCESS',   1, 1, 0),
    (19, 6, 1, 'WORDING_ADMIN_CONFIG_CAPTCHA',  1, 1, 0),
    (20, 6, 1, 'WORDING_ADMIN_CONFIG_MAIL',     1, 1, 0);

INSERT INTO `navbar_internal` (id, page_id)
VALUES
    (1,1),(2,9),(5,8),(6,6),(7,7),(8,19),
    (9,18),(10,14),(11,12),(12,17),(13,11),(14,13),(15,16),(16,15),
    (17,20),(18,21),(19,24),(20,26);

INSERT INTO `navbar_external` (id, url)
VALUES (3,'http://www.example.com'),(4,'http://blackhost.xyz');


-- ----------------------------------------------------------
-- Default admin user
-- ----------------------------------------------------------

INSERT INTO `user` (id, username, password, type, verified, deleted)
VALUES (UNHEX(REPLACE(UUID(), '-', '')), 'Administrator',
        '$argon2id$v=19$m=65536,t=4,p=1$b2Z2cnVLM0pSMy9xUVVicw$6KUaczD3Y6rGl28q61y6YXxriNmGqKv2I6xucl8rcSE',
        1, 1, 0);


-- ----------------------------------------------------------
-- Captcha test page (remove before production)
-- ----------------------------------------------------------

INSERT INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES ('captcha-test', 0, 'captcha_test', 1, 1, 1, 0);

INSERT INTO `page_closure` (ancestor, descendant)
VALUES (LAST_INSERT_ID(), LAST_INSERT_ID());