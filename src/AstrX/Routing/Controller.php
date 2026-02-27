<?php

declare(strict_types = 1);

namespace AstrX\Routing;

use AstrX\Result\Result;

interface Controller
{
    /**
     * Consume from $stack and update $state (bindings).
     * Return:
     * - Result::ok(null) if finished
     * - Result::ok(Controller $next) to delegate further
     * - Result::err(...) for fatal failure
     */
    public function handle(RouteState $state, RouteStack $stack)
    : Result;
}