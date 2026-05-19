<?php
declare(strict_types=1);

namespace AstrX\Auth\Policy;

use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Resource-level policy for Comment objects.
 *
 * Expected resource: object/stdClass with $resource->user_id (hex string|null)
 * Prevents privilege escalation: mods cannot moderate comments by admins.
 *
 * Used by AdminCommentsController and CommentController to enforce
 * fine-grained moderation permissions.
 */
final class CommentPolicy implements PolicyInterface
{
    public function evaluate(Permission $permission, UserSession $session, object $resource): ?bool
    {
        $userIdRaw = $resource->user_id ?? null;
        $userId    = is_scalar($userIdRaw) ? (string)$userIdRaw : '';

        $authorTypeRaw = $resource->user_type ?? null;
        $authorType    = is_int($authorTypeRaw)
            ? $authorTypeRaw
            : (is_numeric($authorTypeRaw) ? (int)$authorTypeRaw : 0);
        $authorGroup   = UserGroup::tryFrom($authorType);

        $isSelf = $session->isLoggedIn() && $userId !== '' && $userId === $session->userId();

        return match ($permission) {
            // Owners can act on their own comments
            Permission::COMMENT_HIDE_OWN,
            Permission::COMMENT_DELETE_OWN => $isSelf,

            // Mods cannot moderate admin comments
            Permission::COMMENT_HIDE_ANY,
            Permission::COMMENT_DELETE_ANY => ($authorGroup === UserGroup::ADMIN
                && $session->userType() !== UserGroup::ADMIN)
                ? false
                : null,

            default => null,
        };
    }
}
