<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Routing\RouteState;
use AstrX\Routing\RouteInput;
use AstrX\Result\Result;

interface Controller
{
    /**
     * Return:
     * - Result::ok(null) finished
     * - Result::ok(Controller $next) delegate
     * - Result::err(...) fatal
     */
    public function handle(RouteState $state, RouteInput $input): Result;

    /**
     * Optional: provide tail segments for rewrite URL building.
     * @return list<string>
     */
    public function buildPath(RouteState $state): array;
}