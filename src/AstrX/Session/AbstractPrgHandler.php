<?php
declare(strict_types=1);

namespace AstrX\Session;

/**
 * Post/Redirect/Get session storage, parameterised by session-key namespace.
 *
 * There were two hand-written copies of this (PrgHandler and CommentPrgHandler)
 * differing only in their key prefixes and query-string key. They drifted, as
 * duplicated code does: the payload garbage collector and the upload-temp-file
 * scrubbing were added to one and not the other, so abandoned comment POST
 * payloads accumulated in the session blob with nothing to remove them —
 * unbounded growth in a MEDIUMBLOB column, i.e. a self-inflicted denial of
 * service on any session that browses comment forms without submitting them.
 * One implementation, two namespaces, no drift.
 *
 * Subclasses supply the three session-key prefixes and the query key; each set
 * must be disjoint from the others, so that (for example) a comment submission
 * redirect is never consumed by a page controller reading the shared `_prg`
 * token before CommentController gets to it.
 */
abstract class AbstractPrgHandler
{
    /** Max seconds a PRG target may sit unused before being pruned. */
    protected const int TARGET_TTL = 3600;

    /** Max number of live PRG targets per session before forced pruning. */
    protected const int TARGET_CAP = 50;

    /** Session-key prefix for stored POST payloads. */
    abstract protected function postPrefix(): string;

    /**
     * Session-key prefix for the parallel per-token creation timestamps that let
     * payloads be aged out. MUST begin with postPrefix() — gcPostPayloads()
     * relies on that to recognise and skip its own meta rows.
     */
    abstract protected function postMetaPrefix(): string;

    /** Session-key prefix for redirect targets. */
    abstract protected function targetPrefix(): string;

    /** Query-string key carrying the PRG token back on the settling GET. */
    abstract public function tokenQueryKey(): string;

    // ── Payload store / retrieve ──────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function store(string $token, array $data): void
    {
        $_SESSION[$this->postPrefix() . $token]     = $data;
        $_SESSION[$this->postMetaPrefix() . $token] = time();
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
        return array_key_exists($this->postPrefix() . $token, $_SESSION);
    }

    /** @return array<string,mixed>|null */
    public function get(string $token): ?array
    {
        $value = $_SESSION[$this->postPrefix() . $token] ?? null;
        if (!is_array($value)) { return null; }
        /** @var array<string,mixed> $value */
        return $value;
    }

    /** @return array<string,mixed>|null */
    public function pull(string $token): ?array
    {
        $key = $this->postPrefix() . $token;

        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key], $_SESSION[$this->postMetaPrefix() . $token]);
        if (!is_array($value)) { return null; }
        /** @var array<string,mixed> $value */
        return $value;
    }

    public function forget(string $token): void
    {
        unset($_SESSION[$this->postPrefix() . $token], $_SESSION[$this->postMetaPrefix() . $token]);
    }

    // ── Target store / retrieve ───────────────────────────────────────────────

    public function createId(string $url): string
    {
        $this->pruneTargets();
        $this->gcPostPayloads();
        $prgId = bin2hex(random_bytes(16));
        $_SESSION[$this->targetPrefix() . $prgId] = [
            'url' => $url,
            'ts'  => time(),
        ];

        return $prgId;
    }

    public function hasTarget(string $prgId): bool
    {
        $val = $_SESSION[$this->targetPrefix() . $prgId] ?? null;
        if ($val === null) { return false; }
        // Legacy format: plain string URL
        if (is_string($val)) { return true; }
        // Current format: array with 'url' and 'ts'
        return is_array($val) && isset($val['url']);
    }

    public function getTarget(string $prgId): ?string
    {
        $value = $_SESSION[$this->targetPrefix() . $prgId] ?? null;
        if (is_string($value)) { return $value; }               // legacy
        if (is_array($value) && isset($value['url'])) {
            $urlVal = $value['url'];
            return is_scalar($urlVal) ? (string) $urlVal : '';
        }
        return null;
    }

    public function forgetTarget(string $prgId): void
    {
        unset($_SESSION[$this->targetPrefix() . $prgId]);
    }

    public function getUrl(string $prgId, ?string $token = null): string
    {
        $url = $this->getTarget($prgId);

        // Unknown id → ''. Callers already guard with hasTarget(), so an empty
        // URL is a safe no-op rather than a programmer-error exception on an
        // ordinary expired/absent target.
        if ($url === null) {
            return '';
        }

        if ($token === null || $token === '') {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . $this->tokenQueryKey() . '=' . rawurlencode($token);
    }

    // ── Pruning ───────────────────────────────────────────────────────────────

    private function pruneTargets(): void
    {
        $prefix = $this->targetPrefix();
        $cutoff = time() - static::TARGET_TTL;
        $count  = 0;
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, $prefix)) { continue; }
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
        if ($count > static::TARGET_CAP) {
            $entries = [];
            foreach ($_SESSION as $key => $value) {
                if (str_starts_with($key, $prefix)) {
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
                if (--$count <= static::TARGET_CAP) { break; }
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
        $postPrefix = $this->postPrefix();
        $metaPrefix = $this->postMetaPrefix();
        $cutoff     = time() - static::TARGET_TTL;

        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, $postPrefix)) { continue; }
            if (str_starts_with($key, $metaPrefix)) { continue; } // its own meta row

            $token   = substr($key, strlen($postPrefix));
            $metaKey = $metaPrefix . $token;
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
}
