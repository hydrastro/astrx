<?php

declare(strict_types = 1);

namespace AstrX\Routing;

use AstrX\Result\Result;

final class Dispatcher
{
    /**
     * Drive controller chain until it returns null next controller.
     */
    public function dispatch(
        Controller $entry,
        RouteState $state,
        RouteStack $stack
    )
    : Result {
        $current = $entry;

        while (true) {
            $r = $current->handle($state, $stack);
            if (!$r->isOk()) {
                return $r;
            }

            $next = $r->unwrap(); // ok payload is either Controller|null
            if ($next === null) {
                return Result::ok(null, $r->diagnostics());
            }
            if (!$next instanceof Controller) {
                // programmer misuse; treat as fatal Result error
                return Result::err(null, $r->diagnostics());
            }
            $current = $next;
        }
    }
}