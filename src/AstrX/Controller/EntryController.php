<?php

declare(strict_types = 1);

namespace AstrX\Controller;

use AstrX\Controller\Controller;
use AstrX\Result\Result;

final class EntryController implements Controller
{
    /**
     * @param callable(string): ?Controller $pageFactory pageId -> controller
     */
    public function __construct(
        private RoutingContext $ctx,
        private $pageFactory
    ) {
    }

    public function handle(RouteState $state, RouteInput $input)
    : Result {
        // Head is already resolved by ContentManager (locale/sid/page).
        // This controller just dispatches to the page controller.
        $page = $state->get($this->ctx->pageKey, 'main')??'main';

        $next = ($this->pageFactory)($page);
        if ($next === null) {
            return Result::ok(null);
        }

        return Result::ok($next);
    }

    public function buildPath(RouteState $state)
    : array {
        return [];
    }
}