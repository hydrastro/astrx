<?php
declare(strict_types=1);

namespace AstrX\Auth\Policy;

use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Resource-level policy for chat messages.
 *
 * Expected resource: a ChatMessageResource ($user_id as a hex string|null,
 * $user_type as an int|null). Prevents privilege escalation: moderators
 * cannot delete messages posted by someone who outranks-or-equals them,
 * unless the actor is an admin.
 *
 * Used by ChatService (registered against ChatMessageResource) to enforce
 * fine-grained moderation permissions.
 */
final class ChatPolicy implements PolicyInterface
{
    public function evaluate(Permission $permission, UserSession $session, object $resource): ?bool
    {
        $authorTypeRaw = $resource->user_type ?? null;
        $authorType    = UserGroup::tryFrom(is_int($authorTypeRaw) ? $authorTypeRaw : 0);

        return match ($permission) {
            // Owners may delete their own messages.
            Permission::CHAT_DELETE_OWN =>
                (isset($resource->user_id) && $resource->user_id === $session->userId()) ? true : false,

            // Mods cannot delete messages by an author whose rank is >= their
            // own, unless the actor is an admin. Guest messages carry a null
            // user_type (rank 0) and therefore stay moderatable.
            Permission::CHAT_DELETE_ANY =>
                ($authorType !== null
                    && $authorType->rank() >= $session->userType()->rank()
                    && $session->userType() !== UserGroup::ADMIN)
                    ? false
                    : null,

            default => null,
        };
    }
}
