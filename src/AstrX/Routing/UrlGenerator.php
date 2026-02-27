<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class UrlGenerator
{
    public function __construct(private Router $router)
    {
    }

    /**
     * @param list<object> $controllers Controllers in order after the page (e.g. page controller + subcontrollers)
     */
    public function url(RouteState $state, array $controllers = [])
    : string {
        $segments = [];

        foreach ($controllers as $c) {
            if (method_exists($c, 'buildPath')) {
                /** @var mixed $part */
                $part = $c->buildPath($state);
                if (is_array($part)) {
                    foreach ($part as $seg) {
                        if (is_string($seg) && $seg !== '') {
                            $segments[] = $seg;
                        }
                    }
                }
            }
        }

        return $this->router->buildUrl($state, $segments);
    }
}