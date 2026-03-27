<?php
declare(strict_types=1);

namespace AstrX\Auth;

use AstrX\Config\InjectConfig;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;

/**
 * Permission gate — the single entry point for all access checks.
 *
 * Usage:
 *   // Simple role check:
 *   if (!$this->gate->can(Permission::ADMIN_NEWS)) { ... }
 *
 *   // Resource-level check (ownership / target privilege):
 *   if (!$this->gate->can(Permission::COMMENT_DELETE_OWN, $commentResource)) { ... }
 *
 * How it works:
 *   1. Resolve the current user's role (UserGroup) from UserSession.
 *   2. Look up the permission in the role→permission map (from config).
 *   3. If the permission is granted at role level AND a Policy exists for
 *      the resource type, delegate to the Policy for a final ruling.
 *   4. Policy returning null = no opinion → role decision stands.
 *   5. Policy returning false = deny (even if role grants it).
 *   6. Policy returning true = allow (narrows .own checks to self).
 *
 * Configuration (Auth.config.php):
 *   'grants' => [
 *       'ADMIN' => ['*'],                          // wildcard: all permissions
 *       'MOD'   => ['comment.*', 'news.view', ...],
 *       'USER'  => ['comment.post', 'user.edit.own', ...],
 *       'GUEST' => ['news.view', 'comment.post'],
 *   ]
 *
 * Wildcard rules:
 *   '*'           — all permissions
 *   'comment.*'   — all permissions whose value starts with 'comment.'
 */
final class Gate
{
    /** @var array<string, list<string>> role name → list of permission patterns */
    private array $grants = [];

    /** @var array<string, PolicyInterface> resource FQCN → policy instance */
    private array $policies = [];

    public function __construct(
        private readonly UserSession $session,
    ) {}

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * @param array<string, list<string>> $grants
     */
    #[InjectConfig('grants')]
    public function setGrants(array $grants): void
    {
        $this->grants = $grants;
    }

    /**
     * Register a policy for a resource class.
     * Called by the controller or a service provider at boot time.
     */
    public function registerPolicy(string $resourceClass, PolicyInterface $policy): void
    {
        $this->policies[$resourceClass] = $policy;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check whether the current user has the given permission, optionally
     * scoped to a specific resource object.
     *
     * @param Permission   $permission
     * @param object|null  $resource   The object being acted on (optional).
     */
    public function can(Permission $permission, ?object $resource = null): bool
    {
        // 1. Role-level check
        if (!$this->roleAllows($permission)) {
            return false;
        }

        // 2. Policy check (only when a resource is provided and a policy exists)
        if ($resource !== null) {
            $policy = $this->findPolicy($resource);
            if ($policy !== null) {
                $verdict = $policy->evaluate($permission, $this->session, $resource);
                if ($verdict !== null) {
                    return $verdict;
                }
            }
        }

        return true;
    }

    /**
     * Inverse of can().
     */
    public function cannot(Permission $permission, ?object $resource = null): bool
    {
        return !$this->can($permission, $resource);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function roleAllows(Permission $permission): bool
    {
        if (!$this->session->isLoggedIn()) {
            $roleName = UserGroup::GUEST->name;
        } else {
            $roleName = $this->session->userType()->name;
        }

        $patterns = $this->grants[$roleName] ?? [];
        $value    = $permission->value;

        foreach ($patterns as $pattern) {
            if ($this->patternMatches($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    private function patternMatches(string $pattern, string $value): bool
    {
        if ($pattern === '*') {
            return true;
        }

        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1); // 'comment.' from 'comment.*'
            return str_starts_with($value, $prefix);
        }

        return $pattern === $value;
    }

    private function findPolicy(object $resource): ?PolicyInterface
    {
        $class = get_class($resource);

        // Exact match
        if (isset($this->policies[$class])) {
            return $this->policies[$class];
        }

        // Interface/parent match
        foreach ($this->policies as $registeredClass => $policy) {
            if ($resource instanceof $registeredClass) {
                return $policy;
            }
        }

        return null;
    }
}
