<?php
declare(strict_types=1);

namespace AstrX\Auth\Policy;

use AstrX\Auth\Permission;
use AstrX\Auth\PolicyInterface;
use AstrX\User\UserSession;

/**
 * Resource-level policy for News items.
 * News has no ownership concept currently — all news is site-wide.
 * This stub exists for future extension (e.g. per-author news).
 */
final class NewsPolicy implements PolicyInterface
{
    public function evaluate(Permission $permission, UserSession $session, object $resource): ?bool
    {
        return null;  // defer to role-level check entirely
    }
}
