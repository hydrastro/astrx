<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. Chat: reading, posting, private messages, moderation and chat settings.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum ChatPermission: string implements Permission
{
    use PermissionId;

    case VIEW         = 'chat.view';
    case POST         = 'chat.post';
    case DELETE_OWN   = 'chat.delete.own';
    case DELETE_ANY   = 'chat.delete.any';
    case MODERATE     = 'chat.moderate';
    case PM           = 'chat.pm';
    case ADMIN_CONFIG = 'admin.config.chat';  // Chat service + rooms
}
