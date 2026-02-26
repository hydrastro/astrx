<?php
/* @noinspection PhpUnused */

namespace AstrX;

enum EnvironmentType : int

{
    case DEVELOPMENT = 0;
    case PRODUCTION = 1;
    case TESTING = 2;
    case STAGING = 3;

    public function isDevLike(): bool
    {
        return $this === self::DEVELOPMENT || $this === self::TESTING;
    }

    public function isProdLike(): bool
    {
        return $this === self::PRODUCTION || $this === self::STAGING;
    }
}