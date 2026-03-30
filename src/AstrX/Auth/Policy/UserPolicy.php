<?php
declare(strict_types=1);

namespace AstrX\Auth\Policy;

use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Resource-level policy for User objects.
 *
 * Expected resource: object with $resource->id (hex string)
 * Prevents privilege escalation: mods cannot edit admins.
 */
final class UserPolicy implements PolicyInterface
{
    public function evaluate(Permission $permission, UserSession $session, object $resource): ?bool
    {
        $isSelf = $session->isLoggedIn()
            && isset($resource->id)
            && $resource->id === $session->userId();

        return match ($permission) {
            Permission::USER_EDIT_OWN,
            Permission::USER_DELETE_OWN => $isSelf ? true : false,

            // Mods cannot edit/delete admins
            Permission::USER_EDIT_ANY,
            Permission::USER_DELETE_ANY => (
                isset($resource->type)
                && UserGroup::tryFrom(is_int(($rt = $resource->type)) ? $rt : (is_numeric($rt) ? (int)$rt : 0)) === UserGroup::ADMIN
                && $session->userType() !== UserGroup::ADMIN
            ) ? false : null,

            default => null,
        };
    }
}
