<?php
declare(strict_types=1);

namespace AstrX\Comment;

use AstrX\Auth\Permission;
use AstrX\Auth\PermissionId;

/**
 * Permissions owned by this feature. Comments: posting, hiding, deleting, flagging and administering comments.
 *
 * The string values are the stable identifiers granted to roles in
 * Auth.config.php (wildcards such as 'admin.*' match them by prefix); never
 * change one without migrating that config.
 */
enum CommentPermission: string implements Permission
{
    use PermissionId;

    case POST         = 'comment.post';
    case HIDE_OWN     = 'comment.hide.own';
    case HIDE_ANY     = 'comment.hide.any';
    case DELETE_OWN   = 'comment.delete.own';
    case DELETE_ANY   = 'comment.delete.any';
    case FLAG         = 'comment.flag';  // ---- Chat ---------------------------------------------------------------
    case ADMIN        = 'admin.comments';
    case ADMIN_CONFIG = 'admin.config.comments';  // Comment service + antispam
}
