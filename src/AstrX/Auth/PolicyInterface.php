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
 *
 * A policy answers for exactly the permissions it lists in governs(). Gate
 * refuses any resource-scoped check whose permission is NOT in that list, so a
 * permission the policy never considered can no longer fall through to "allow".
 */
interface PolicyInterface
{
    /**
     * The permissions this policy is prepared to rule on.
     *
     * Gate consults evaluate() for these and DENIES any other resource-scoped
     * check routed to this policy. Keep this list and evaluate()'s match arms in
     * step: a permission listed here but missing from the match throws (see each
     * policy's default arm), and a permission handled in the match but missing
     * here is never reached. tests/auth_policy_test.php asserts both directions
     * for every registered policy.
     *
     * @return list<Permission>
     */
    public function governs(): array;

    /**
     * @param Permission  $permission  The permission being checked; always one of governs().
     * @param UserSession $session     The current user
     * @param object      $resource    The resource being acted on
     */
    public function evaluate(
        Permission  $permission,
        UserSession $session,
        object      $resource,
    ): PolicyVerdict;
}
