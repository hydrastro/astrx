<?php
declare(strict_types=1);

namespace AstrX\Session;

use RuntimeException;

final class PrgHandler
{
    private const POST_PREFIX = 'POST_';
    private const TARGET_PREFIX = 'PRG_TARGET_';
    private const TOKEN_QUERY_KEY = '_prg';

    /** @param array<string,mixed> $data */
    public function store(string $token, array $data): void
    {
        $_SESSION[self::POST_PREFIX . $token] = $data;
    }

    /** @param array<string,mixed> $data */
    public function storeFromPayload(array $data): string
    {
        $token = bin2hex(random_bytes(32));
        $this->store($token, $data);

        return $token;
    }

    public function has(string $token): bool
    {
        return array_key_exists(self::POST_PREFIX . $token, $_SESSION);
    }

    /** @return array<string,mixed>|null */
    public function get(string $token): ?array
    {
        $value = $_SESSION[self::POST_PREFIX . $token] ?? null;

        return is_array($value) ? $value : null;
    }

    /** @return array<string,mixed>|null */
    public function pull(string $token): ?array
    {
        $key = self::POST_PREFIX . $token;

        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_array($value) ? $value : null;
    }

    public function forget(string $token): void
    {
        unset($_SESSION[self::POST_PREFIX . $token]);
    }

    public function createId(string $url): string
    {
        $prgId = bin2hex(random_bytes(16));
        $_SESSION[self::TARGET_PREFIX . $prgId] = $url;

        return $prgId;
    }

    public function hasTarget(string $prgId): bool
    {
        return array_key_exists(self::TARGET_PREFIX . $prgId, $_SESSION);
    }

    public function getTarget(string $prgId): ?string
    {
        $value = $_SESSION[self::TARGET_PREFIX . $prgId] ?? null;

        return is_string($value) ? $value : null;
    }

    public function forgetTarget(string $prgId): void
    {
        unset($_SESSION[self::TARGET_PREFIX . $prgId]);
    }


    // todo change this.
    public function getUrl(string $prgId, ?string $token = null): string
    {
        $url = $this->getTarget($prgId);

        if ($url === null) {
            throw new RuntimeException(sprintf(
                                            'Unknown PRG target id "%s".',
                                            $prgId,
                                        ));
        }

        if ($token === null || $token === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . self::TOKEN_QUERY_KEY . '=' . rawurlencode($token);
    }

    public function tokenQueryKey(): string
    {
        return self::TOKEN_QUERY_KEY;
    }
}
