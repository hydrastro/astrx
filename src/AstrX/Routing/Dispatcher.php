<?php
declare(strict_types=1);

namespace AstrX\Routing;

use AstrX\Result\Result;

final class Dispatcher
{
    public function __construct(private ?RoutingAliasLoader $aliasLoader = null, private ?string $locale = null) {}

    public function dispatch(\AstrX\Controller\Controller $entry, RouteState $state, RouteInput $input): Result
    {
        $current = $entry;

        while (true) {
            // If query mode and controller declares a routing domain, extend aliases
            if ($input instanceof QueryRouteInput && $current instanceof RoutingDomainInterface && $this->aliasLoader && $this->locale) {
                $domainAliases = $this->aliasLoader->loadDomain($this->locale, $current->routingDomain());
                $input = $input->withAliases($domainAliases);
            }

            $r = $current->handle($state, $input);
            if (!$r->isOk()) return $r;

            $next = $r->unwrap();
            if ($next === null) return Result::ok(null, $r->diagnostics());

            if (!$next instanceof \AstrX\Controller\Controller) {
                return Result::err(null, $r->diagnostics());
            }

            $current = $next;
        }
    }
}