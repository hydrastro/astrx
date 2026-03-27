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
    /** @return array<string,mixed>|null */
    public function get(string $token): ?array
    {
        $value = $_SESSION[self::POST_PREFIX . $token] ?? null;
        if (!is_array($value)) { return null; }
        /** @var array<string,mixed> $value */
        return $value;
    }

    /** @return array<string,mixed>|null */
    public function pull(string $token): ?array
    {
        $key = self::POST_PREFIX . $token;

        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        if (!is_array($value)) { return null; }
        /** @var array<string,mixed> $value */
        return $value;
    }

    public function forget(string $token): void
    {
        unset($_SESSION[self::POST_PREFIX . $token]);
    }

    /** Max seconds a PRG target may sit unused before being pruned. */
    private const TARGET_TTL = 3600;

    /** Max number of live PRG targets per session before forced pruning. */
    private const TARGET_CAP = 50;

    public function createId(string $url): string
    {
        $this->pruneTargets();
        $prgId = bin2hex(random_bytes(16));
        $_SESSION[self::TARGET_PREFIX . $prgId] = [
            'url' => $url,
            'ts'  => time(),
        ];

        return $prgId;
    }

    private function pruneTargets(): void
    {
        $cutoff = time() - self::TARGET_TTL;
        $count  = 0;
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, self::TARGET_PREFIX)) { continue; }
            $count++;
            if (is_array($value)) {
                /** @var array<string,mixed> $value */
                $tsRaw = $value['ts'] ?? 0;
                $ts = is_int($tsRaw) ? $tsRaw : 0;
            } else { $ts = 0; }
            if ($ts < $cutoff) {
                unset($_SESSION[$key]);
                $count--;
            }
        }
        // Hard cap: evict oldest entries if still over limit
        if ($count > self::TARGET_CAP) {
            $entries = [];
            foreach ($_SESSION as $key => $value) {
                if (str_starts_with($key, self::TARGET_PREFIX)) {
                    if (is_array($value)) {
                        /** @var array<string,mixed> $value */
                        $tsRaw2 = $value['ts'] ?? 0;
                        $entries[$key] = is_int($tsRaw2) ? $tsRaw2 : 0;
                    } else { $entries[$key] = 0; }
                }
            }
            asort($entries); // oldest first
            foreach (array_keys($entries) as $key) {
                unset($_SESSION[$key]);
                if (--$count <= self::TARGET_CAP) { break; }
            }
        }
    }

    public function hasTarget(string $prgId): bool
    {
        $val = $_SESSION[self::TARGET_PREFIX . $prgId] ?? null;
        if ($val === null) { return false; }
        // Legacy format: plain string URL
        if (is_string($val)) { return true; }
        // New format: array with 'url' and 'ts'
        if (is_array($val) && isset($val['url'])) { return true; }
        return false;
    }

    public function getTarget(string $prgId): ?string
    {
        $value = $_SESSION[self::TARGET_PREFIX . $prgId] ?? null;
        if (is_string($value)) { return $value; }               // legacy
        if (is_array($value) && isset($value['url'])) {
            $urlVal = $value['url'];
            return is_scalar($urlVal) ? (string)$urlVal : '';
        }
        return null;
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
