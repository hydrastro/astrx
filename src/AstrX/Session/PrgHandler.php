<?php
declare(strict_types=1);

namespace AstrX\Session;

final class PrgHandler
{
    private const POST_PREFIX = 'POST_';
    /** Parallel per-token creation timestamp, so payloads can be aged out (R3-27). */
    private const POST_META_PREFIX = 'POST_META_';
    private const TARGET_PREFIX = 'PRG_TARGET_';
    private const TOKEN_QUERY_KEY = '_prg';

    /** @param array<string,mixed> $data */
    public function store(string $token, array $data): void
    {
        $_SESSION[self::POST_PREFIX . $token]      = $data;
        $_SESSION[self::POST_META_PREFIX . $token] = time();
        $this->gcPostPayloads();
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
        unset($_SESSION[$key], $_SESSION[self::POST_META_PREFIX . $token]);
        if (!is_array($value)) { return null; }
        /** @var array<string,mixed> $value */
        return $value;
    }

    public function forget(string $token): void
    {
        unset($_SESSION[self::POST_PREFIX . $token], $_SESSION[self::POST_META_PREFIX . $token]);
    }

    /** Max seconds a PRG target may sit unused before being pruned. */
    private const TARGET_TTL = 3600;

    /** Max number of live PRG targets per session before forced pruning. */
    private const TARGET_CAP = 50;

    public function createId(string $url): string
    {
        $this->pruneTargets();
        $this->gcPostPayloads();
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

    /**
     * Sweep abandoned PRG POST payloads and the upload temp files they hold
     * (R3-27). A payload is stored on submit and consumed by pull() on the
     * settling GET; if the user abandons the redirect (or it fails validation
     * and is never pulled), the payload — and any upload files ContentManager
     * moved into the system temp dir and recorded under __files__ — would linger
     * forever. Prune payloads older than the PRG TTL, unlinking their temp files
     * first. A missing meta timestamp means a pre-fix (legacy) entry, also aged
     * out. Runs on store() and createId(), the frequent PRG entry points.
     */
    private function gcPostPayloads(): void
    {
        $cutoff = time() - self::TARGET_TTL;
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, self::POST_PREFIX)) { continue; }
            if (str_starts_with($key, self::POST_META_PREFIX)) { continue; } // its own meta row

            $token   = substr($key, strlen(self::POST_PREFIX));
            $metaKey = self::POST_META_PREFIX . $token;
            $tsRaw   = $_SESSION[$metaKey] ?? null;
            $ts      = is_int($tsRaw) ? $tsRaw : 0;

            if ($ts >= $cutoff) { continue; }

            if (is_array($value)) {
                /** @var array<string,mixed> $value */
                $this->purgeUploadTemps($value);
            }
            unset($_SESSION[$key], $_SESSION[$metaKey]);
        }
    }

    /**
     * Unlink upload temp files recorded in a PRG payload's __files__ block. Only
     * paths whose basename carries ContentManager's 'astrx_upload_' prefix are
     * touched, so the sweep can never remove anything it did not create.
     *
     * @param array<string,mixed> $payload
     */
    private function purgeUploadTemps(array $payload): void
    {
        $files = $payload['__files__'] ?? null;
        if (!is_array($files)) { return; }
        foreach ($files as $meta) {
            if (!is_array($meta)) { continue; }
            $path = $meta['temp_path'] ?? null;
            if (!is_string($path) || $path === '') { continue; }
            if (!str_starts_with(basename($path), 'astrx_upload_')) { continue; }
            if (is_file($path)) { @unlink($path); }
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


    public function getUrl(string $prgId, ?string $token = null): string
    {
        $url = $this->getTarget($prgId);

        // Unknown id → return '' to mirror CommentPrgHandler::getUrl. Callers
        // already guard with hasTarget(), so an empty URL is a safe no-op rather
        // than a programmer-error exception on ordinary expired/absent targets.
        if ($url === null) {
            return '';
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
