<?php

declare(strict_types = 1);

namespace AstrX\Session;

/**
 * Dedicated PRG handler for comment forms.
 * Uses a separate session namespace (COMMENT_POST_ / COMMENT_TARGET_) and
 * a separate query key (_cp) so that comment form redirects are never
 * consumed by other page controllers that share the main _prg query key.
 * This solves the bug where pages with their own controller (e.g. UserController)
 * call PrgHandler::pull() on the shared _prg token before CommentController
 * can process it, silently dropping the comment submission.
 */
final class CommentPrgHandler
{
    private const POST_PREFIX = 'COMMENT_POST_';
    private const TARGET_PREFIX = 'COMMENT_TARGET_';
    public const QUERY_KEY = '_cp';
    private const TARGET_TTL = 3600;
    private const TARGET_CAP = 50;
    // ── Payload store/retrieve ────────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    public function store(string $token, array $data)
    : void {
        $_SESSION[self::POST_PREFIX . $token] = $data;
    }

    /** @param array<string,mixed> $data */
    public function storeFromPayload(array $data)
    : string {
        $token = bin2hex(random_bytes(32));
        $this->store($token, $data);

        return $token;
    }

    /** @return array<string,mixed>|null */
    public function pull(string $token)
    : ?array {
        $key = self::POST_PREFIX . $token;
        $value = $_SESSION[$key]??null;
        unset($_SESSION[$key]);

        return is_array($value) ? $value : null;
    }

    public function has(string $token)
    : bool {
        return isset($_SESSION[self::POST_PREFIX . $token]);
    }

    // ── Target store/retrieve ─────────────────────────────────────────────────

    public function createId(string $url)
    : string {
        $this->pruneTargets();
        $id = bin2hex(random_bytes(16));
        $_SESSION[self::TARGET_PREFIX . $id] = ['url' => $url, 'ts' => time()];

        return $id;
    }

    public function hasTarget(string $id)
    : bool {
        $val = $_SESSION[self::TARGET_PREFIX . $id]??null;

        return is_array($val) && isset($val['url']);
    }

    public function getTarget(string $id)
    : ?string {
        $val = $_SESSION[self::TARGET_PREFIX . $id]??null;

        return is_array($val) ? ($val['url']??null) : null;
    }

    public function forgetTarget(string $id)
    : void {
        unset($_SESSION[self::TARGET_PREFIX . $id]);
    }

    public function getUrl(string $id, string $token)
    : string {
        $url = $this->getTarget($id);
        if ($url === null) {
            return '';
        }
        $sep = str_contains($url, '?') ? '&' : '?';

        return $url . $sep . self::QUERY_KEY . '=' . rawurlencode($token);
    }

    public function tokenQueryKey()
    : string
    {
        return self::QUERY_KEY;
    }

    // ── TTL pruning ───────────────────────────────────────────────────────────

    private function pruneTargets()
    : void
    {
        $cutoff = time() - self::TARGET_TTL;
        $count = 0;
        foreach ($_SESSION as $key => $value) {
            if (!str_starts_with($key, self::TARGET_PREFIX)) {
                continue;
            }
            $count++;
            $ts = is_array($value) ? (int)($value['ts']??0) : 0;
            if ($ts < $cutoff) {
                unset($_SESSION[$key]);
                $count--;
            }
        }
        if ($count > self::TARGET_CAP) {
            $entries = [];
            foreach ($_SESSION as $key => $value) {
                if (str_starts_with($key, self::TARGET_PREFIX)) {
                    $entries[$key] = is_array($value) ? (int)($value['ts']??0) :
                        0;
                }
            }
            asort($entries);
            foreach (array_keys($entries) as $key) {
                unset($_SESSION[$key]);
                if (--$count <= self::TARGET_CAP) {
                    break;
                }
            }
        }
    }
}