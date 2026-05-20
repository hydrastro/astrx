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
