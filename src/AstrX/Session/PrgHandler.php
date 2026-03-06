<?php
declare(strict_types=1);

namespace AstrX\Session;

final class PrgHandler
{
    private const PREFIX = 'POST_';

    /** @param array<string,mixed> $data */
    public function store(string $token, array $data): void
    {
        $_SESSION[self::PREFIX . $token] = $data;
    }

    /** @return array<string,mixed> */
    public function load(string $token): array
    {
        $v = $_SESSION[self::PREFIX . $token] ?? [];
        return is_array($v) ? $v : [];
    }

    public function clear(string $token): void
    {
        unset($_SESSION[self::PREFIX . $token]);
    }
}