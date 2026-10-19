<?php
declare(strict_types=1);

namespace AstrX\Routing;

/** What RouteParser read from a request. */
final class RouteMatch
{
    public function __construct(
        public readonly string  $locale,
        /** A session id found in the URL (cookieless mode only), not yet validated against the store. */
        public readonly ?string $sidCandidate,
        /** The page token; the default page when the URL names none. */
        public readonly string  $pageToken,
    ) {}
}
