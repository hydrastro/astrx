<?php

declare(strict_types = 1);

namespace AstrX\User;

/**
 * User group / role.
 * Values mirror the `type` column in the `user` table.
 */
enum UserGroup: int
{
    case USER = 0;
    case ADMIN = 1;
    case MOD = 2;
    case GUEST = 3;
}