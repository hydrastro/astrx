<?php
declare(strict_types=1);
namespace AstrX\Auth;

/** Shared account and installer password bounds. */
final class PasswordPolicy
{
    public const int MIN_LENGTH = 12;
    public const int MAX_BYTES = 4096;
    public static function accepts(string $password): bool
    {
        return strlen($password) <= self::MAX_BYTES
            && mb_check_encoding($password, 'UTF-8')
            && mb_strlen($password, 'UTF-8') >= self::MIN_LENGTH;
    }
}
