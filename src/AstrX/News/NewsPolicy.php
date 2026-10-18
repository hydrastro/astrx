<?php
declare(strict_types=1);

namespace AstrX\News;

use AstrX\Auth\Identity;
use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\Auth\PolicyVerdict;

/**
 * Resource-level policy for News items.
 * News has no ownership concept currently — all news is site-wide, and nothing
 * passes a news resource to Gate::can(). This stub exists for future extension
 * (e.g. per-author news).
 *
 * governs() is deliberately empty: Gate denies any resource-scoped check routed
 * here, which is the correct answer for a policy that has no rules yet. It is
 * NOT the same as the old `return null`, which allowed everything.
 */
final class NewsPolicy implements PolicyInterface
{
    /** @return list<Permission> */
    public function governs(): array
    {
        return [];
    }

    public function evaluate(Permission $permission, Identity $identity, object $resource): PolicyVerdict
    {
        // Unreachable: Gate only calls evaluate() for permissions in governs(),
        // which is empty. Throwing here means "somebody added a permission to
        // governs() and forgot the rule" rather than a quiet allow.
        throw new \LogicException(
            'NewsPolicy has no rules; ' . $permission->value . ' should not have been routed to it.'
        );
    }
}
