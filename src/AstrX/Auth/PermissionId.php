<?php
declare(strict_types=1);

namespace AstrX\Auth;

/**
 * Implements Permission::id() for a string-backed permission enum.
 *
 * @phpstan-require-implements Permission
 */
trait PermissionId
{
    public function id(): string
    {
        return (string) $this->value;
    }
}
