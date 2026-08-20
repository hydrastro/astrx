<?php
declare(strict_types=1);

namespace AstrX\Auth\Policy;

use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Auth\PolicyVerdict;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Resource-level policy for Comment objects.
 *
 * Expected resource: object/stdClass with $resource->user_id (hex string|null)
 * and $resource->user_type (int|null — NULL for a guest comment).
 * Prevents privilege escalation: mods cannot moderate comments by admins.
 *
 * Used by AdminCommentsController and CommentService to enforce
 * fine-grained moderation permissions.
 *
 * NOTE: unlike UserPolicy this still duck-types a \stdClass rather than taking
 * a typed CommentResource. Its only two construction sites (CommentService and
 * AdminCommentsController) both cast a repository row with `(object)`, and the
 * typed equivalent would have to be introduced there. `user_type` comes from a
 * LEFT JOIN and is legitimately NULL for guest comments, so — unlike a user
 * account, where "unknown group" must fail closed — the missing case here has a
 * real meaning (GUEST, rank 0) and is spelled out below rather than being cast
 * to 0, which is UserGroup::USER.
 */
final class CommentPolicy implements PolicyInterface
{
    /** @return list<Permission> */
    public function governs(): array
    {
        return [
            Permission::COMMENT_HIDE_OWN,
            Permission::COMMENT_DELETE_OWN,
            Permission::COMMENT_HIDE_ANY,
            Permission::COMMENT_DELETE_ANY,
        ];
    }

    public function evaluate(Permission $permission, UserSession $session, object $resource): PolicyVerdict
    {
        $userIdRaw = $resource->user_id ?? null;
        $userId    = is_scalar($userIdRaw) ? (string) $userIdRaw : '';

        // A NULL/absent author type is a GUEST comment (rank 0), NOT USER — whose
        // enum value happens to be 0. Same reading ChatPolicy uses.
        $authorTypeRaw = $resource->user_type ?? null;
        $authorGroup   = UserGroup::tryFrom(
            is_int($authorTypeRaw)
                ? $authorTypeRaw
                : (is_numeric($authorTypeRaw) ? (int) $authorTypeRaw : UserGroup::GUEST->value)
        ) ?? UserGroup::GUEST;

        $isSelf = $session->isLoggedIn() && $userId !== '' && $userId === $session->userId();

        return match ($permission) {
            // Owners can act on their own comments
            Permission::COMMENT_HIDE_OWN,
            Permission::COMMENT_DELETE_OWN => $isSelf ? PolicyVerdict::Allow : PolicyVerdict::Deny,

            // Mods cannot moderate admin comments
            Permission::COMMENT_HIDE_ANY,
            Permission::COMMENT_DELETE_ANY => ($authorGroup === UserGroup::ADMIN
                && $session->userType() !== UserGroup::ADMIN)
                ? PolicyVerdict::Deny
                : PolicyVerdict::Abstain,

            // Unreachable while this match and governs() agree — Gate never
            // routes anything else here. Throwing makes the drift loud instead
            // of turning a forgotten permission into a silent allow.
            default => throw new \LogicException(
                'CommentPolicy::governs() lists ' . $permission->value . ' but evaluate() has no arm for it.'
            ),
        };
    }
}
