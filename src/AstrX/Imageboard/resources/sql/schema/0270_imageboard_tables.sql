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
