<?php
declare(strict_types=1);

namespace AstrX\Auth;

/**
 * All named permissions in the system.
 *
 * Naming convention: {resource}.{action}[.{scope}]
 *   scope = 'any' (any record) | 'own' (owned by current user)
 *   If no scope suffix, the permission applies regardless of ownership.
 *
 * These are the leaves of the PBAC tree. Roles map to subsets of these.
 * Config (Auth.config.php) assigns permissions to UserGroup cases — change
 * who can do what without touching PHP.
 */
enum Permission: string
{
    // ---- News ---------------------------------------------------------------
    case NEWS_VIEW       = 'news.view';
    case NEWS_CREATE     = 'news.create';
    case NEWS_EDIT_ANY   = 'news.edit.any';
    case NEWS_DELETE_ANY = 'news.delete.any';

    // ---- Comments -----------------------------------------------------------
    case COMMENT_POST        = 'comment.post';
    case COMMENT_HIDE_OWN    = 'comment.hide.own';
    case COMMENT_HIDE_ANY    = 'comment.hide.any';
    case COMMENT_DELETE_OWN  = 'comment.delete.own';
    case COMMENT_DELETE_ANY  = 'comment.delete.any';
    case COMMENT_FLAG        = 'comment.flag';

    // ---- Chat ---------------------------------------------------------------
    case CHAT_VIEW         = 'chat.view';
    case CHAT_POST         = 'chat.post';
    case CHAT_DELETE_OWN   = 'chat.delete.own';
    case CHAT_DELETE_ANY   = 'chat.delete.any';
    case CHAT_MODERATE     = 'chat.moderate';
    case CHAT_PM           = 'chat.pm';
    case ADMIN_CONFIG_CHAT = 'admin.config.chat';   // Chat service + rooms

    // ---- Imageboard ---------------------------------------------------------
    case BOARD_VIEW              = 'board.view';
    case BOARD_POST              = 'board.post';
    case BOARD_DELETE_OWN        = 'board.delete.own';
    case BOARD_MODERATE          = 'board.moderate';           // delete/ban/sticky/lock on any board
    case BOARD_ADMIN             = 'board.admin';              // create/configure/delete boards
    case ADMIN_CONFIG_IMAGEBOARD = 'admin.config.imageboard';  // global imageboard defaults

    // ---- Users --------------------------------------------------------------
    case USER_VIEW_PUBLIC   = 'user.view.public';
    case USER_EDIT_OWN      = 'user.edit.own';
    case USER_EDIT_ANY      = 'user.edit.any';
    case USER_DELETE_OWN    = 'user.delete.own';
    case USER_DELETE_ANY    = 'user.delete.any';
    case USER_PROMOTE       = 'user.promote';     // change another user's group

    // ---- Banlist ------------------------------------------------------------
    case BAN_VIEW    = 'ban.view';
    case BAN_CREATE  = 'ban.create';
    case BAN_REVOKE  = 'ban.revoke';

    // ---- Admin panel --------------------------------------------------------
    case ADMIN_ACCESS    = 'admin.access';     // enter the admin section
    case ADMIN_NEWS      = 'admin.news';
    case ADMIN_COMMENTS  = 'admin.comments';
    case ADMIN_USERS     = 'admin.users';
    case ADMIN_BANLIST   = 'admin.banlist';
    case ADMIN_NAVBAR    = 'admin.navbar';
    case ADMIN_PAGES     = 'admin.pages';
    case ADMIN_NOTES     = 'admin.notes';

    // ---- Admin config sections ----------------------------------------------
    // Each section has its own permission so partial admin access is possible
    // (e.g. a trusted moderator can edit Comments config but not System config).
    case ADMIN_CONFIG_SYSTEM   = 'admin.config.system';   // Core + Routing + Session + Template + …
    case ADMIN_CONFIG_ACCESS   = 'admin.config.access';   // Auth grants + Banlist routes
    case ADMIN_CONFIG_CONTENT  = 'admin.config.content';  // News pagination
    case ADMIN_CONFIG_COMMENTS = 'admin.config.comments'; // Comment service + antispam
    case ADMIN_CONFIG_CAPTCHA  = 'admin.config.captcha';  // CaptchaService + CaptchaRenderer
    case ADMIN_CONFIG_USERS    = 'admin.config.users';    // UserService + AvatarService + Identicon
    case ADMIN_CONFIG_MAIL     = 'admin.config.mail';     // Mailer + MailboxManager

    // ---- Webmail ------------------------------------------------------------
    case WEBMAIL_ACCESS  = 'webmail.access';   // access the webmail UI
    case WEBMAIL_SEND    = 'webmail.send';     // send emails via webmail

    // ── API key management (fix103) ───────────────────────────────────────
    case API_KEY_CREATE = 'api.key.create';   // user can create their own API keys
    case API_KEY_REVOKE = 'api.key.revoke';   // user can revoke their own API keys

    // ---- Audit log ----------------------------------------------------------
    case ADMIN_AUDIT_LOG = 'admin.audit_log';  // view the admin audit log
}