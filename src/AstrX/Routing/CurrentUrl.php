<?php
declare(strict_types=1);

namespace AstrX\Routing;

/**
 * Canonical key/value bag for the current request.
 *
 * Populated by ContentManager during routing:
 *   - locale, session_key, page_key  — set for both routing modes
 *   - url_tail                        — rewrite mode only: the URL path
 *     segments that remain after locale + session_id + page_token have
 *     been consumed. Controllers use these for page-specific sub-params.
 *
 * Example: /en/main/3/asc/10
 *   locale    = 'en'
 *   page      = 'WORDING_MAIN' (resolved)
 *   url_tail  = ['3', 'asc', '10']
 */
final class CurrentUrl
{
    /** @var array<string, string> */
    private array $params = [];

    /** @var list<string> */
    private array $tail = [];

    public function set(string $key, string $value): void
    {
        $this->params[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->params);
    }

    public function get(string $key, mixed $fallback = null): mixed
    {
        return $this->params[$key] ?? $fallback;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->params;
    }

    // -------------------------------------------------------------------------
    // Tail — remaining path segments after routing head is consumed
    // -------------------------------------------------------------------------

    /**
     * Store remaining URL segments after the routing head is consumed.
     * Called by ContentManager (rewrite mode only).
     *
     * @param list<string> $segments
     */
    public function setTail(array $segments): void
    {
        $this->tail = $segments;
    }

    /**
     * Get a tail segment by 0-based position, or null if absent.
     */
    public function tailSegment(int $index): ?string
    {
        return $this->tail[$index] ?? null;
    }

    /**
     * All remaining tail segments.
     *
     * @return list<string>
     */
    public function tail(): array
    {
        return $this->tail;
    }
}
