<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. Accounts: viewing profiles and editing, deleting or promoting accounts.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum UserPermission: string implements Permission
{
    use PermissionId;

    case VIEW_PUBLIC = 'user.view.public';
    case EDIT_OWN    = 'user.edit.own';
    case EDIT_ANY    = 'user.edit.any';
    case DELETE_OWN  = 'user.delete.own';
    case DELETE_ANY  = 'user.delete.any';
    case PROMOTE     = 'user.promote';  // change another user's group
}
