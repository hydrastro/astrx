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
