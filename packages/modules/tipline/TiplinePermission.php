<?php
declare(strict_types=1);

namespace AstrX\Tipline;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. Tipline: administering this module (its admin page).
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum TiplinePermission: string implements Permission
{
    use PermissionId;

    case ADMIN = 'admin.tipline';  // manage the anonymous tip line (pubkey + queue)
}
