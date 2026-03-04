<?php
declare(strict_types=1);

namespace AstrX\Routing;

final class RoutingConfig
{
    public function __construct(
        public readonly UrlMode $mode,
        public readonly string $basePath,    // e.g. "/"
        public readonly string $entryPoint,  // e.g. "index.php" (query mode)
    ) {}
}