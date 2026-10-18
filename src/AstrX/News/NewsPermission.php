<?php
declare(strict_types=1);

namespace AstrX\News;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. News: reading, writing and administering news posts.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum NewsPermission: string implements Permission
{
    use PermissionId;

    case VIEW         = 'news.view';
    case CREATE       = 'news.create';
    case EDIT_ANY     = 'news.edit.any';
    case DELETE_ANY   = 'news.delete.any';  // ---- Comments -----------------------------------------------------------
    case ADMIN        = 'admin.news';
    case ADMIN_CONFIG = 'admin.config.content';  // News pagination
}
