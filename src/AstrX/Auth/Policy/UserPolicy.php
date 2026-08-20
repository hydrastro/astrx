<?php
declare(strict_types=1);

namespace AstrX\Auth\Policy;

use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Auth\PolicyVerdict;
use AstrX\User\UserGroup;
use AstrX\User\UserResource;
use AstrX\User\UserSession;

/**
 * Resource-level policy for USER accounts.
 *
 * Expected resource: UserResource (a typed id + UserGroup, built by
 * UserResource::fromRow()). Registered against UserResource::class — NOT
 * against UserRepository::class, which is never passed as a resource and so
 * left this policy dead code while CommentPolicy (registered for \stdClass,
 * which is what `(object) $userRow` produces) silently answered every user.*
 * check with "no opinion".
 *
 * Prevents privilege escalation: an actor who is not an ADMIN cannot edit,
 * delete or re-group an account that ranks at or above their own.
 */
final class UserPolicy implements PolicyInterface
{
    /** @return list<Permission> */
    public function governs(): array
    {
        return [
            Permission::USER_EDIT_OWN,
            Permission::USER_DELETE_OWN,
            Permission::USER_EDIT_ANY,
            Permission::USER_DELETE_ANY,
            // USER_PROMOTE has a call site (AdminUsersController) but was NOT
            // handled by the old match: it fell through `default => null` to
            // "allow" — the exact silent-forget this interface now prevents.
            Permission::USER_PROMOTE,
        ];
    }

    public function evaluate(Permission $permission, UserSession $session, object $resource): PolicyVerdict
    {
        // Registered for UserResource only. A different object here means a
        // caller passed the wrong resource type for a user.* permission (the
        // original defect); refuse rather than guess at its fields.
        if (!$resource instanceof UserResource) {
            return PolicyVerdict::Deny;
        }

        $isSelf = $session->isLoggedIn()
            && $resource->id !== ''
            && $resource->id === strtolower($session->userId());

        // Compare by PRIVILEGE RANK, never the raw enum value: UserGroup's
        // integers are not privilege-ordered (USER=0, ADMIN=1, MOD=2, GUEST=3),
        // so a numeric comparison would rank a MOD (2) above an ADMIN (1).
        $targetOutranksActor = $resource->type->rank() >= $session->userType()->rank();

        return match ($permission) {
            Permission::USER_EDIT_OWN,
            Permission::USER_DELETE_OWN => $isSelf ? PolicyVerdict::Allow : PolicyVerdict::Deny,

            Permission::USER_EDIT_ANY,
            Permission::USER_DELETE_ANY,
            Permission::USER_PROMOTE =>
                // Admins are unconstrained at this level (callers still apply
                // their own rules — e.g. AdminUsersController's no-self-promotion
                // check). Acting on your own row is not escalation. Otherwise:
                // refuse a target ranking at or above the actor, so a MOD cannot
                // reach an ADMIN — nor a peer MOD, whose account is an equally
                // good stepping stone to one.
                ($session->userType() === UserGroup::ADMIN || $isSelf || !$targetOutranksActor)
                    ? PolicyVerdict::Abstain
                    : PolicyVerdict::Deny,

            // Unreachable while this match and governs() agree — Gate never
            // routes anything else here. Throwing makes the drift loud instead
            // of turning a forgotten permission into a silent allow.
            default => throw new \LogicException(
                'UserPolicy::governs() lists ' . $permission->value . ' but evaluate() has no arm for it.'
            ),
        };
    }
}
