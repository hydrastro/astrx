<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class RoutingContext
{
    /**
     * @param list<string> $availableLocales
     */
    public function __construct(
        public readonly string $defaultLocale,
        public readonly array $availableLocales,
        public readonly bool $sessionUseCookies,
        public readonly string $sessionIdRegex,

        // canonical keys (ContentManager-owned)
        public readonly string $localeKey,   // e.g. "lang"
        public readonly string $sessionKey,  // e.g. "sid"
        public readonly string $pageKey,     // e.g. "page"
    )
    {
    }

    public function isLocale(string $s)
    : bool {
        return in_array($s, $this->availableLocales, true);
    }

    public function isSessionId(string $s)
    : bool {
        return preg_match($this->sessionIdRegex, $s) === 1;
    }
}