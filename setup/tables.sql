USE content_manager;

-- ============================================================
-- PAGE SYSTEM
-- ============================================================

CREATE TABLE `page`
(
    `id`         INT         NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `url_id`     VARCHAR(64) NOT NULL UNIQUE,   -- public URL token; a Translator key when i18n=1
    `i18n`       TINYINT     NOT NULL DEFAULT 0, -- 1 = url_id resolved through Translator
    `file_name`  VARCHAR(64) NOT NULL,           -- physical file under page/ dir (no extension)
    `template`   TINYINT     NOT NULL DEFAULT 1, -- 1 = render via TemplateEngine
    `controller` TINYINT     NOT NULL DEFAULT 0, -- 1 = dispatch AstrX\Controller\{Name}Controller
    `hidden`     TINYINT     NOT NULL DEFAULT 0, -- 1 = not routable (error / fallback pages)
    `comments`   TINYINT     NOT NULL DEFAULT 0  -- 1 = comment section enabled on this page
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
    `description` VARCHAR(160) NOT NULL DEFAULT '', -- 160 chars = SEO meta-description standard
    FOREIGN KEY (page_id) REFERENCES page (id) ON UPDATE CASCADE ON DELETE CASCADE
);

-- Closure table: every ancestor–descendant pair, including self-references (depth=0).
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
    `i18n`    TINYINT     NOT NULL DEFAULT 0 -- 1 = keyword is a Translator key
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
    `file_name` VARCHAR(64) NOT NULL -- filename under template/ dir (no extension)
);

-- Optional per-page template override; pages without a row use the config default.
CREATE TABLE `page_template`
(
    `page_id`     INT NOT NULL PRIMARY KEY,
    `template_id` INT NOT NULL,
    FOREIGN KEY (page_id)     REFERENCES page     (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES template (id) ON UPDATE CASCADE ON DELETE CASCADE
);


-- ============================================================
-- NAVIGATION BAR
-- navigation_bar_ids is a shared sequence so internal and external entries never collide.
-- ============================================================

CREATE TABLE `navigation_bar_ids`
(
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY
);

CREATE TABLE `navigation_bar_entry`
(
    `id`       INT         NOT NULL PRIMARY KEY,
    `internal` TINYINT     NOT NULL,              -- 1 = links to an internal page, 0 = external URL
    `name`     VARCHAR(64) NOT NULL,              -- display label; Translator key when i18n=1
    `i18n`     TINYINT     NOT NULL DEFAULT 0,
    `active`   TINYINT     NOT NULL DEFAULT 1,    -- 0 = hidden from rendered navbar
    FOREIGN KEY (id) REFERENCES navigation_bar_ids (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `navigation_bar_internal`
(
    `id`      INT NOT NULL PRIMARY KEY,
    `page_id` INT NOT NULL,
    FOREIGN KEY (id)      REFERENCES navigation_bar_entry (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES page                 (id) ON UPDATE CASCADE ON DELETE CASCADE
);

CREATE TABLE `navigation_bar_external`
(
    `id`  INT           NOT NULL PRIMARY KEY,
    `url` VARCHAR(2083) NOT NULL,
    FOREIGN KEY (id) REFERENCES navigation_bar_entry (id) ON UPDATE CASCADE ON DELETE CASCADE
);


-- ============================================================
-- SESSION  (DB-backed — managed by AstrX\Session\SecureSessionHandler)
-- SecureSessionHandler stores SHA-512(raw_sid) as the row key.
-- SHA-512 hex output = 128 chars exactly → VARCHAR(128).
-- Data is AES-256-CTR encrypted + HMAC-SHA256 authenticated.
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

-- Note: navigation_bar_external is joined ON e.id (not on internal.id — that was a bug in the
-- original draft). Exactly one of (page_id / url_id) or (url) will be non-NULL per row.
CREATE VIEW resolved_navigation_bar AS
SELECT e.id,
       e.internal,
       e.name,
       e.i18n,
       e.active,
       ni.page_id,
       ne.url,
       p.url_id,
       p.i18n AS page_i18n
FROM `navigation_bar_entry` e
         LEFT JOIN `navigation_bar_internal` ni ON ni.id    = e.id
         LEFT JOIN `navigation_bar_external` ne ON ne.id    = e.id  -- fixed: was wrongly ne ON ni.id
         LEFT JOIN `page`                    p  ON p.id     = ni.page_id
ORDER BY e.id;


-- ============================================================
-- USER SYSTEM
-- id: BINARY(16) — 16 raw random bytes from random_bytes(16).
--     Retrieve with HEX(id); insert with UNHEX(?).
-- Soft-delete: deleted=1 + all PII columns NULLed.
--     The row is kept so comment / ban FKs remain valid.
-- Single-token slot: one active token per user; token_type determines its purpose.
-- ============================================================

CREATE TABLE `user`
(
    `id`               BINARY(16)   NOT NULL PRIMARY KEY,
    `username`         VARCHAR(64)  NULL UNIQUE,
    `password`         VARCHAR(255) NULL,              -- argon2id via password_hash(); never md5/sha1
    `mailbox`          VARCHAR(320) NULL UNIQUE,       -- login identifier (local-part of address)
    `email`            VARCHAR(320) NULL UNIQUE,       -- recovery / verification address
    `display_name`     VARCHAR(64)  NULL,
    `type`             TINYINT      NOT NULL DEFAULT 0, -- 0=user 1=admin 2=mod 3=guest
    `birth`            DATE         NULL,
    `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_access`      TIMESTAMP    NULL,
    `login_attempts`   INT          NOT NULL DEFAULT 0,
    `verified`         TINYINT      NOT NULL DEFAULT 0,
    `avatar`           TINYINT      NOT NULL DEFAULT 0, -- 1 = custom avatar file on disk
    `deleted`          TINYINT      NOT NULL DEFAULT 0,
    -- Single active token slot (type determines meaning)
    `token_hash`       VARCHAR(255) NULL,               -- password_hash() of the raw token
    `token_type`       TINYINT      NULL,               -- 0=recover 1=email_change 2=verify 3=delete
    `token_used`       TINYINT      NOT NULL DEFAULT 0, -- 1 = token already consumed
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
-- ip: packed binary via inet_pton() — 4 bytes for IPv4, 16 for IPv6.
-- reply_to: SET NULL (not CASCADE) so child comments survive parent deletion.
-- ============================================================

CREATE TABLE `comment`
(
    `id`          INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `page_id`     INT           NOT NULL,
    `user_id`     BINARY(16)    NULL,                  -- NULL for anonymous commenters
    `name`        VARCHAR(64)   NULL,                  -- anonymous display name
    `email`       VARCHAR(320)  NULL,                  -- optional anonymous contact
    `content`     TEXT          NOT NULL,
    `reply_to`    INT           NULL,
    `ip`          VARBINARY(16) NULL,                  -- packed IPv4 (4 B) or IPv6 (16 B)
    `hidden`      TINYINT       NOT NULL DEFAULT 0,
    `flagged`     TINYINT       NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (page_id)  REFERENCES page    (id) ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES user    (id) ON UPDATE CASCADE ON DELETE SET NULL,
    FOREIGN KEY (reply_to) REFERENCES comment (id) ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_page    (page_id),
    INDEX idx_user    (user_id),
    INDEX idx_created (created_at)
);


-- ============================================================
-- SECURITY: CAPTCHA
-- id: bin2hex(random_bytes(16)) — 32 hex chars.
-- Expired rows should be purged periodically (e.g. via a cron or on-request).
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
-- Multi-tier, progressive ban system.
-- ban_route: 0=permanent  1=bad_comment  2=failed_login
-- penalty_round escalates per infraction cycle; tries counts within check_time window.
-- ============================================================

CREATE TABLE `banlist`
(
    `id`            INT       NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ban_route`     TINYINT   NOT NULL,               -- 0=permanent 1=bad_comment 2=failed_login
    `penalty_round` SMALLINT  NOT NULL DEFAULT 0,
    `tries`         SMALLINT  NOT NULL DEFAULT 0,
    `reason`        TEXT      NOT NULL DEFAULT '',
    `start`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `end`           TIMESTAMP NULL,                   -- NULL = permanent ban
    `check_time`    TIMESTAMP NULL,                   -- window start for try-counting
    `active`        TINYINT   NOT NULL DEFAULT 1,
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

-- IPv4: stored as unsigned INT; use INET_ATON() / INET_NTOA() in queries.
CREATE TABLE `banlist_ipv4`
(
    `ban_id`     INT          NOT NULL PRIMARY KEY,
    `ipv4_start` INT UNSIGNED NOT NULL,
    `ipv4_end`   INT UNSIGNED NOT NULL,
    FOREIGN KEY (ban_id) REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_range (ipv4_start, ipv4_end)
);

-- IPv6: stored as raw 16-byte binary; use INET6_ATON() / INET6_NTOA() in queries.
CREATE TABLE `banlist_ipv6`
(
    `ban_id`     INT        NOT NULL PRIMARY KEY,
    `ipv6_start` BINARY(16) NOT NULL,
    `ipv6_end`   BINARY(16) NOT NULL,
    FOREIGN KEY (ban_id) REFERENCES banlist (id) ON UPDATE CASCADE ON DELETE CASCADE
);


-- ============================================================
-- DATA INSERTIONS
-- ============================================================

-- ----------------------------------------------------------
-- Pages
--
-- ID map (referenced throughout this file):
--   1  main            9  user (section root)
--   2  error           10 avatar
--   3  login           11 admin_banlist
--   4  register        12 admin_comments
--   5  recover         13 admin_navbar
--   6  profile         14 admin_news
--   7  user_settings   15 admin_notes
--   8  user_home       16 admin_pages
--                      17 admin_users
--                      18 admin (section root)
--
-- Hierarchy:
--   (top-level)
--   ├── main      (1)
--   ├── error     (2)  hidden — framework fallback
--   ├── user      (9)  section root
--   │   ├── login          (3)
--   │   ├── register       (4)
--   │   ├── recover        (5)
--   │   ├── profile        (6)
--   │   ├── user_settings  (7)
--   │   └── user_home      (8)
--   ├── avatar    (10) raw output, no template
--   └── admin     (18) section root
--       ├── admin_banlist   (11)
--       ├── admin_comments  (12)
--       ├── admin_navbar    (13)
--       ├── admin_news      (14)
--       ├── admin_notes     (15)
--       ├── admin_pages     (16)
--       └── admin_users     (17)
-- ----------------------------------------------------------

INSERT INTO `page` (url_id, i18n, file_name, template, controller, hidden, comments)
VALUES
-- Framework defaults
('WORDING_MAIN',  1, 'main',  1, 1, 0, 1),  -- id=1  (comments enabled on the main page)
('WORDING_ERROR', 1, 'error', 1, 1, 1, 0),  -- id=2

-- User-facing pages
('login',         1, 'login',         1, 1, 0, 0),  -- id=3
('register',      1, 'register',      1, 1, 0, 0),  -- id=4
('recover',       1, 'recover',       1, 1, 0, 0),  -- id=5
('profile',       1, 'profile',       1, 1, 0, 0),  -- id=6
('settings',      1, 'user_settings', 1, 1, 0, 0),  -- id=7
('home',          1, 'user_home',     1, 1, 0, 0),  -- id=8
('user',          1, 'user',          1, 1, 0, 0),  -- id=9  section root

-- Special: raw image binary output, no template wrapping
('avatar',        0, 'avatar',        0, 1, 0, 0),  -- id=10

-- Admin pages (i18n=0: admin URLs are not locale-dependent)
('admin_banlist',  0, 'admin_banlist',  1, 1, 0, 0), -- id=11
('admin_comments', 0, 'admin_comments', 1, 1, 0, 0), -- id=12
('admin_navbar',   0, 'admin_navbar',   1, 1, 0, 0), -- id=13
('admin_news',     0, 'admin_news',     1, 1, 0, 0), -- id=14
('admin_notes',    0, 'admin_notes',    1, 1, 0, 0), -- id=15
('admin_pages',    0, 'admin_pages',    1, 1, 0, 0), -- id=16
('admin_users',    0, 'admin_users',    1, 1, 0, 0), -- id=17
('admin',          0, 'admin',          1, 1, 0, 0); -- id=18


-- ----------------------------------------------------------
-- Robots meta
-- ----------------------------------------------------------

INSERT INTO `page_robots` (page_id, `index`, follow)
VALUES
    (1,  1, 1),  -- main           — indexable
    (2,  0, 0),  -- error          — noindex, nofollow
    (3,  1, 1),  -- login
    (4,  1, 1),  -- register
    (5,  1, 1),  -- recover
    (6,  0, 0),  -- profile        — noindex (user privacy)
    (7,  0, 0),  -- user_settings  — noindex
    (8,  0, 0),  -- user_home      — noindex
    (9,  1, 1),  -- user           — indexable section root
    (10, 0, 0),  -- avatar         — raw endpoint, noindex
    (11, 0, 0),  -- admin_banlist
    (12, 0, 0),  -- admin_comments
    (13, 0, 0),  -- admin_navbar
    (14, 0, 0),  -- admin_news
    (15, 0, 0),  -- admin_notes
    (16, 0, 0),  -- admin_pages
    (17, 0, 0),  -- admin_users
    (18, 0, 0);  -- admin


-- ----------------------------------------------------------
-- Meta  (title / description used as HTML fallback when no Translator entry is found)
-- ----------------------------------------------------------

INSERT INTO `page_meta` (page_id, title, description)
VALUES
    (1,  'My Website',            'This is my awesome website!'),
    (2,  'Error',                 'An error occurred.'),
    (3,  'Login',                 'Log in to your account.'),
    (4,  'Register',              'Create a new account.'),
    (5,  'Recover',               'Recover your account password.'),
    (6,  'User Profile',          'View a user profile.'),
    (7,  'Settings',              'Manage your account settings.'),
    (8,  'Home',                  'Welcome to your home page.'),
    (9,  'User Area',             'Log in or create your account.'),
    (10, '',                      ''),
    (11, 'Admin — Banlist',       'Manage the banlist.'),
    (12, 'Admin — Comments',      'Moderate site comments.'),
    (13, 'Admin — Navbar',        'Edit the navigation bar.'),
    (14, 'Admin — News',          'Manage news posts.'),
    (15, 'Admin — Notes',         'Personal admin notes.'),
    (16, 'Admin — Pages',         'Manage site pages.'),
    (17, 'Admin — Users',         'Manage user accounts.'),
    (18, 'Administration',        'Administration area.');


-- ----------------------------------------------------------
-- Page closure  (all ancestor–descendant pairs, self-refs at depth=0)
-- This is a flat two-level hierarchy, so no transitive rows beyond depth=1 are needed.
-- ----------------------------------------------------------

INSERT INTO `page_closure` (ancestor, descendant)
VALUES
-- Self-references (depth=0)
(1,1),(2,2),(3,3),(4,4),(5,5),(6,6),(7,7),
(8,8),(9,9),(10,10),(11,11),(12,12),(13,13),
(14,14),(15,15),(16,16),(17,17),(18,18),

-- user section (9) → children
(9,3),(9,4),(9,5),(9,6),(9,7),(9,8),

-- admin section (18) → children
(18,11),(18,12),(18,13),(18,14),(18,15),(18,16),(18,17);


-- ----------------------------------------------------------
-- Keywords  (same 21 entries as old schema, IDs preserved)
-- ----------------------------------------------------------

INSERT INTO `keyword` (keyword, i18n)
VALUES
    ('WORDING_MAIN_PAGE',   1),  -- id=1  (was 'Main' — now a Translator key)
    ('WORDING_INDEX',       1),  -- id=2  (was 'Index')
    ('User',                0),  -- id=3
    ('Profile',             0),  -- id=4
    ('Login',               0),  -- id=5
    ('Register',            0),  -- id=6
    ('Main Page',           0),  -- id=7
    ('User Area',           0),  -- id=8
    ('Registration',        0),  -- id=9
    ('Recover',             0),  -- id=10
    ('Lost Password',       0),  -- id=11
    ('Admin',               0),  -- id=12
    ('Administration Area', 0),  -- id=13
    ('Settings',            0),  -- id=14
    ('Banlist',             0),  -- id=15
    ('Comments',            0),  -- id=16
    ('Navbar',              0),  -- id=17
    ('News',                0),  -- id=18
    ('Notes',               0),  -- id=19
    ('Pages',               0),  -- id=20
    ('Users',               0);  -- id=21


-- ----------------------------------------------------------
-- Page keywords  (old string page_id → new integer page_id)
-- ----------------------------------------------------------

INSERT INTO `page_keyword` (page_id, keyword_id)
VALUES
-- main (1)
(1,1),(1,2),(1,7),
-- login (3)
(3,5),(3,3),
-- register (4)
(4,6),(4,3),(4,8),(4,9),
-- recover (5)
(5,10),(5,11),(5,3),(5,1),
-- profile (6)
(6,3),(6,4),
-- user_settings (7)
(7,3),(7,8),(7,14),
-- user section root (9)
(9,3),(9,8),
-- admin section root (18)
(18,12),(18,13),(18,14),
-- admin_banlist (11)
(11,12),(11,13),(11,14),(11,15),
-- admin_comments (12)
(12,12),(12,13),(12,14),(12,16),
-- admin_navbar (13)
(13,12),(13,13),(13,14),(13,17),
-- admin_news (14)
(14,12),(14,13),(14,14),(14,18),
-- admin_notes (15)
(15,12),(15,13),(15,14),(15,19),
-- admin_pages (16)
(16,12),(16,13),(16,14),(16,20),
-- admin_users (17)
(17,12),(17,13),(17,14),(17,21);


-- ----------------------------------------------------------
-- Navigation bar
-- Mirrors the old schema: Home→main, User→user, plus two inactive external entries.
-- ----------------------------------------------------------

INSERT INTO `navigation_bar_ids` ()
VALUES (),(),(),();

INSERT INTO `navigation_bar_entry` (id, internal, name, i18n, active)
VALUES
    (1, 1, 'WORDING_HOME', 1, 1),  -- Home → main (active)
    (2, 1, 'WORDING_USER', 1, 1),  -- User → user section (active)
    (3, 0, 'Test',         0, 0),  -- external test link (inactive)
    (4, 0, 'Ext',          0, 0);  -- external link (inactive)

INSERT INTO `navigation_bar_internal` (id, page_id)
VALUES
    (1, 1),   -- Home → main (id=1)
    (2, 9);   -- User → user section (id=9)

INSERT INTO `navigation_bar_external` (id, url)
VALUES
    (3, 'http://www.example.com'),
    (4, 'http://blackhost.xyz');


-- ----------------------------------------------------------
-- Default admin user
-- The empty password string is intentionally unusable — no argon2id hash will ever
-- verify against ''. A proper setup wizard must call password_hash() before use.
-- ----------------------------------------------------------

INSERT INTO `user` (id, username, type, verified, deleted)
VALUES (UNHEX(REPLACE(UUID(), '-', '')), 'Administrator', 1, 1, 0);