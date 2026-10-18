<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Auth\Gate;
use AstrX\Auth\PolicyInterface;
use AstrX\Comment\CommentPolicy;
use AstrX\Comment\CommentRepository;
use AstrX\News\NewsPolicy;
use AstrX\News\NewsRepository;
use AstrX\User\UserPolicy;
use AstrX\User\UserResource;

/**
 * Application-level policy wiring (replaces Auth\GateBootstrapper).
 *
 * The security layer (AstrX\Auth) must not name the features it protects, so
 * the resource → policy table lives here, in the application layer next to
 * Prelude and ContentManager. Always-on features are listed in core(); optional
 * modules declare theirs in their manifest's `policies` key and are registered
 * only while enabled (ModuleRegistry::enabledPolicies()).
 *
 * A policy is keyed by the class of the object callers actually PASS to
 * Gate::can(), not by the class that loads it. UserPolicy used to be keyed on
 * UserRepository::class, which is never passed as a resource: every
 * user.edit.any / user.delete.any / user.promote check fell to the \stdClass
 * entry (because AdminUsersController built its target with `(object) $row`)
 * and was answered by CommentPolicy, which has no arm for any of them.
 * UserResource exists so that binding is expressible only one way; see its
 * class docblock.
 */
final class ApplicationPolicies
{
    /**
     * Typed resource policies of the always-on features.
     *
     * @return array<string, class-string<PolicyInterface>>
     */
    public static function core(): array
    {
        return [
            CommentRepository::class => CommentPolicy::class,
            NewsRepository::class    => NewsPolicy::class,
            UserResource::class      => UserPolicy::class,
        ];
    }

    /**
     * Register core policies, then module-declared ones, then the \stdClass
     * fallback. Order matters: Gate matches an exact class first and otherwise
     * the FIRST registered parent/interface, so the catch-all goes last.
     *
     * @param array<string,string> $modulePolicies resource class => policy class
     *                                             (ModuleRegistry::enabledPolicies())
     */
    public static function register(Gate $gate, array $modulePolicies = []): void
    {
        foreach (self::core() + $modulePolicies as $resource => $policyClass) {
            $gate->registerPolicy($resource, self::instantiate($policyClass));
        }

        // stdClass resources (comment rows cast with `(object)` by CommentService
        // and AdminCommentsController). Keep this LAST-resort entry limited to
        // comments: any other subsystem that needs a resource-level ruling must
        // introduce its own typed resource class rather than reuse \stdClass.
        $gate->registerPolicy(\stdClass::class, new CommentPolicy());
    }

    private static function instantiate(string $policyClass): PolicyInterface
    {
        if (!class_exists($policyClass)) {
            throw new \LogicException("Policy class {$policyClass} does not exist.");
        }
        $policy = new $policyClass();
        if (!$policy instanceof PolicyInterface) {
            throw new \LogicException("{$policyClass} does not implement " . PolicyInterface::class . '.');
        }
        return $policy;
    }
}
