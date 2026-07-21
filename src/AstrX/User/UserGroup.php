<?php
declare(strict_types=1);

namespace AstrX\User;

/**
 * User group / role.
 * Values mirror the `type` column in the `user` table.
 */
enum UserGroup: int
{
    case USER  = 0;
    case ADMIN = 1;
    case MOD   = 2;
    case GUEST = 3;

    /**
     * Privilege rank (higher = more powerful). NOTE: the enum's integer values
     * are NOT privilege-ordered, so any "can this actor set that group" check
     * MUST compare rank(), never the raw ->value.
     */
    public function rank(): int
    {
        return match ($this) {
            self::ADMIN => 3,
            self::MOD   => 2,
            self::USER  => 1,
            self::GUEST => 0,
        };
    }
}