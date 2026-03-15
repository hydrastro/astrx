<?php
declare(strict_types=1);

namespace AstrX\Auth;

use AstrX\User\UserSession;

/**
 * Optional resource-level policy interface.
 *
 * When Gate::can($permission, $resource) is called, Gate checks whether
 * a Policy exists for the resource type and delegates to it if so.
 * The role-level permission check must PASS first — policies can only
 * further restrict, never grant access that roles deny.
 */
interface PolicyInterface
{
    /**
     * @param Permission           $permission  The permission being checked
     * @param UserSession          $session     The current user
     * @param object               $resource    The resource being acted on
     * @return bool|null           true = allow, false = deny, null = no opinion
     */
    public function evaluate(
        Permission  $permission,
        UserSession $session,
        object      $resource,
    ): ?bool;
}
