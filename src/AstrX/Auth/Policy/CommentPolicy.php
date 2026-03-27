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
 * Expected resource shape: object with $resource->userId (hex string|null)
 * Covers the .own scope: a mod can hide their own comments without
 * needing COMMENT_HIDE_ANY, and a user can delete their own comment.
 */
final class CommentPolicy implements PolicyInterface
{
    public function evaluate(Permission $permission, UserSession $session, object $resource): ?bool
    {
        $isOwner = $session->isLoggedIn()
            && isset($resource->userId)
            && $resource->userId === $session->userId();

        return match ($permission) {
            Permission::COMMENT_HIDE_OWN,
            Permission::COMMENT_DELETE_OWN => $isOwner ? true : false,
            default => null,  // no opinion on other permissions
        };
    }
}
