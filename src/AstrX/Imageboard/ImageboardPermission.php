<?php
declare(strict_types=1);

namespace AstrX\Imageboard;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. Imageboard: viewing, posting, moderating and administering boards.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum ImageboardPermission: string implements Permission
{
    use PermissionId;

    case VIEW         = 'board.view';
    case POST         = 'board.post';
    case DELETE_OWN   = 'board.delete.own';
    case MODERATE     = 'board.moderate';  // delete/ban/sticky/lock on any board
    case ADMIN        = 'board.admin';  // create/configure/delete boards
    case ADMIN_CONFIG = 'admin.config.imageboard';  // global imageboard defaults
}
