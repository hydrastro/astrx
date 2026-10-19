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
