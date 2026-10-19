<?php
declare(strict_types=1);

namespace AstrX\Chat;

use AstrX\Auth\Identity;
use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Auth\PolicyVerdict;
use AstrX\User\UserGroup;

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
    /** @return list<Permission> */
    public function governs(): array
    {
        return [
            ChatPermission::DELETE_OWN,
            ChatPermission::DELETE_ANY,
        ];
    }

    public function evaluate(Permission $permission, Identity $identity, object $resource): PolicyVerdict
    {
        $authorTypeRaw = $resource->user_type ?? null;
        // A null/unknown author type is a GUEST message (rank 0) — NOT USER, whose
        // enum value happens to be 0. Passing 0 previously mislabelled guest posts
        // as USER (rank 1), diverging from this class's own comment and CommentPolicy.
        $authorType    = UserGroup::tryFrom(is_int($authorTypeRaw) ? $authorTypeRaw : UserGroup::GUEST->value)
            ?? UserGroup::GUEST;

        return match ($permission) {
            // Owners may delete their own messages.
            ChatPermission::DELETE_OWN =>
                (isset($resource->user_id) && $resource->user_id === $identity->userId())
                    ? PolicyVerdict::Allow
                    : PolicyVerdict::Deny,

            // Mods cannot delete messages by an author whose rank is >= their
            // own, unless the actor is an admin. Guest messages carry a null
            // user_type (rank 0) and therefore stay moderatable.
            ChatPermission::DELETE_ANY =>
                ($authorType->rank() >= UserGroup::ofIdentity($identity)->rank()
                    && UserGroup::ofIdentity($identity) !== UserGroup::ADMIN)
                    ? PolicyVerdict::Deny
                    : PolicyVerdict::Abstain,

            // Unreachable while this match and governs() agree — Gate never
            // routes anything else here. Throwing makes the drift loud instead
            // of turning a forgotten permission into a silent allow.
            default => throw new \LogicException(
                'ChatPolicy::governs() lists ' . $permission->value . ' but evaluate() has no arm for it.'
            ),
        };
    }
}
