<?php
declare(strict_types=1);

namespace AstrX\Routing;

final class UrlGenerator
{
    public function __construct(
        private Router $router,
        private RoutingContext $ctx
    ) {}

    /**
     * @param list<object> $controllers controllers that may implement buildPath(RouteState): array
     */
    public function url(RouteState $state, array $controllers = []): string
    {
        $segments = [];

        foreach ($controllers as $c) {
            if (method_exists($c, 'buildPath')) {
                $part = $c->buildPath($state);
                if (is_array($part)) {
                    foreach ($part as $seg) {
                        if (is_string($seg) && $seg !== '') $segments[] = $seg;
                    }
                }
            }
        }

        return $this->router->buildUrl($state, $segments, $this->ctx);
    }
}