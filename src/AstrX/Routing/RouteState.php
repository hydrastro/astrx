<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class RouteState
{
    /** @var array<string, string|null> canonicalKey => value */
    private array $params = [];
    /** @var list<string> rewrite-mode ordered segments (already url-decoded, will be encoded on build) */
    private array $pathSegments = [];

    public function set(string $canonicalKey, ?string $value)
    : void {
        $this->params[$canonicalKey] = $value;
    }

    public function get(string $canonicalKey, ?string $default = null)
    : ?string {
        return array_key_exists($canonicalKey, $this->params) ?
            $this->params[$canonicalKey] : $default;
    }

    /** @return array<string, string|null> */
    public function all()
    : array
    {
        return $this->params;
    }

    /** Append to rewrite path */
    public function pushPath(?string $segment)
    : void {
        if ($segment === null) {
            return;
        }
        $this->pathSegments[] = $segment;
    }

    /** @param list<string> $segments */
    public function pushPathMany(array $segments)
    : void {
        foreach ($segments as $s) {
            $this->pushPath($s);
        }
    }

    /** @return list<string> */
    public function pathSegments()
    : array
    {
        return $this->pathSegments;
    }

    public function resetPath()
    : void
    {
        $this->pathSegments = [];
    }
}