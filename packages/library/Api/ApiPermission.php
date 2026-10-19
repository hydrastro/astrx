<?php
declare(strict_types=1);

namespace AstrX\Api;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. API keys: creating and revoking your own keys.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum ApiPermission: string implements Permission
{
    use PermissionId;

    case KEY_CREATE = 'api.key.create';  // user can create their own API keys
    case KEY_REVOKE = 'api.key.revoke';  // user can revoke their own API keys
}
