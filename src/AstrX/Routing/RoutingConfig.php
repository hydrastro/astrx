<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class RoutingConfig
{
    public function __construct(
        public readonly UrlMode $mode,
        public readonly string $basePath,                 // e.g. "/"
        public readonly string $entryPoint,
        // e.g. "index.php" (query mode)
        public readonly string $defaultLocale,            // e.g. "en"
        /** @var list<string> */ public readonly array $availableLocales,
        // e.g. ["en","it"]
        public readonly bool $sessionUseCookies,
        // if false => session may appear as optional head (rewrite) or as query param (query)
        public readonly string $sessionIdRegex,
        // e.g. '/^[\da-fA-F]{64,256}$/'
        public readonly string $localeKey,
        // canonical key "lang"
        public readonly string $sessionKey,               // canonical key "sid"
        public readonly string $pageKey,                  // canonical key "page"
    )
    {
    }
}