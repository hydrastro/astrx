<?php
declare(strict_types=1);

namespace AstrX\User;

use AstrX\Auth\Identity;

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
    /**
     * The group an Identity carries, by role name — what policies compare.
     *
     * For a UserSession this is exactly userType(). A role name that is not a
     * UserGroup case maps to GUEST (least privilege), never to USER.
     */
    public static function ofIdentity(Identity $identity): self
    {
        if ($identity instanceof UserSession) {
            return $identity->userType();
        }
        foreach (self::cases() as $case) {
            if ($case->name === $identity->roleName()) {
                return $case;
            }
        }
        return self::GUEST;
    }

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