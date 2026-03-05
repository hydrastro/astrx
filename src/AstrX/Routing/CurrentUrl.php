<?php
declare(strict_types=1);

namespace AstrX\Routing;

/**
 * Canonical key/value bag for the *current request* (rewrite mode).
 * O(1) lookup.
 */
final class CurrentUrl
{
    /** @var array<string, string> */
    private array $params = [];

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
}