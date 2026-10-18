<?php
declare(strict_types=1);

namespace AstrX\Auth;

/**
 * A named permission: the leaves of the PBAC tree that roles are granted.
 *
 * Each feature declares its own permissions as a string-backed enum that
 * implements this interface (Chat\ChatPermission, Admin\AdminPermission, …),
 * so adding a feature never means editing the security layer. The enum's
 * value is the stable identifier granted in Auth.config.php.
 *
 * Naming convention for values: {resource}.{action}[.{scope}]
 *   scope = 'any' (any record) | 'own' (owned by the current user)
 *   No scope suffix: the permission applies regardless of ownership.
 *
 * Every implementation is a backed enum, so ->value, ->name and identity
 * comparison (===, in_array(..., true)) work exactly as they did on the old
 * single Permission enum. Use id() where a typed string is needed.
 */
interface Permission extends \BackedEnum
{
    /** The stable identifier, e.g. 'chat.moderate' (the enum's value). */
    public function id(): string;
}
