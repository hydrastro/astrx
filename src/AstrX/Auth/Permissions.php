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
}
