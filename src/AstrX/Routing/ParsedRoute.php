<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class ParsedRoute
{
    public function __construct(
        public readonly RouteState $state,
        public readonly RouteStack $stack
    ) {
    }
}