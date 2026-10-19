<?php
declare(strict_types=1);

namespace AstrX\Auth;

/**
 * A resource-level policy's ruling on one permission check.
 *
 * Replaces the old `?bool` return of PolicyInterface::evaluate(), where `null`
 * meant "no opinion". That encoding was dangerous in combination with the
 * `default => null` arm every policy carried: a permission a policy had simply
 * FORGOTTEN produced the same value as a permission it had deliberately
 * declined to rule on, and both resolved to "the role decision stands" — i.e.
 * allow. Three named cases make the deliberate abstention explicit and let
 * Gate treat "this policy has no rule for this permission" (see
 * PolicyInterface::governs()) as a denial instead of an accident.
 */
enum PolicyVerdict
{
    /** Permit, regardless of what the role grant would have decided next. */
    case Allow;

    /** Refuse, even though the role grant allows it. */
    case Deny;

    /**
     * Deliberately no opinion: the policy has a rule for this permission but it
     * did not fire, so the role-level decision stands. NOT the same as "this
     * policy does not know about this permission" — that is expressed by
     * leaving the permission out of governs(), which Gate treats as a denial.
     */
    case Abstain;
}
