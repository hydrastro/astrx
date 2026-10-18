<?php
declare(strict_types=1);

namespace AstrX\Webmail;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. Webmail: using the webmail UI and sending mail.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum WebmailPermission: string implements Permission
{
    use PermissionId;

    case ACCESS = 'webmail.access';  // access the webmail UI
    case SEND   = 'webmail.send';  // send emails via webmail
}
