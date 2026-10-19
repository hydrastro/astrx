<?php
declare(strict_types=1);

namespace AstrX\Admin;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. The admin shell and site-wide settings: entering the admin section, site configuration, users, pages, navigation, audit log, panic lockdown and the ban list.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum AdminPermission: string implements Permission
{
    use PermissionId;

    case BAN_VIEW       = 'ban.view';
    case BAN_CREATE     = 'ban.create';
    case BAN_REVOKE     = 'ban.revoke';  // ---- Admin panel --------------------------------------------------------
    case ACCESS         = 'admin.access';  // enter the admin section
    case USERS          = 'admin.users';
    case BANLIST        = 'admin.banlist';
    case NAVBAR         = 'admin.navbar';
    case PAGES          = 'admin.pages';
    case NOTES          = 'admin.notes';  // ---- Admin config sections ----------------------------------------------
    case CONFIG_SYSTEM  = 'admin.config.system';  // Core + Routing + Session + Template + …
    case CONFIG_ACCESS  = 'admin.config.access';  // Auth grants + Banlist routes
    case CONFIG_CAPTCHA = 'admin.config.captcha';  // CaptchaService + CaptchaRenderer
    case CONFIG_USERS   = 'admin.config.users';  // UserService + AvatarService + Identicon
    case CONFIG_MAIL    = 'admin.config.mail';  // Mailer + MailboxManager
    case AUDIT_LOG      = 'admin.audit_log';  // view the admin audit log
    case PANIC          = 'admin.panic';  // arm / disarm the site-wide panic lockdown
}
