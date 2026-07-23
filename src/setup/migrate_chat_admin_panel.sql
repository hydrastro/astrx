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
