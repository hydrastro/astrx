<?php

declare(strict_types = 1);

namespace AstrX\Routing;

/**
 * Query mode input: canonical keys are resolved via alias maps:
 * canonicalKey -> externalKey.
 */
final class QueryRouteInput implements RouteInput
{
    /** @param array<string,string> $query externalKey => value */
    public function __construct(
        private array $query,
        /** @var array<string,string> canonical => external */
        private array $aliases
    ) {
    }

    public function mode()
    : UrlMode
    {
        return UrlMode::QUERY;
    }

    public function peekSegment()
    : ?string
    {
        return null;
    }

    public function takeSegment()
    : ?string
    {
        return null;
    }

    public function hasMoreSegments()
    : bool
    {
        return false;
    }

    public function remainingSegments()
    : array
    {
        return [];
    }

    public function queryValue(string $canonicalKey)
    : ?string {
        $ext = $this->aliases[$canonicalKey]??$canonicalKey;

        return $this->query[$ext]??null;
    }

    /**
     * @param array<string,string> $aliases canonical => external
     */
    public function withAliases(array $aliases)
    : self {
        // Merge: domain aliases override existing
        return new self($this->query, array_merge($this->aliases, $aliases));
    }
}