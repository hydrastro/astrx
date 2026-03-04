<?php

declare(strict_types = 1);

namespace AstrX\Routing;

final class RewriteRouteInput implements RouteInput
{
    public function __construct(private RouteStack $stack)
    {
    }

    public function mode()
    : UrlMode
    {
        return UrlMode::REWRITE;
    }

    public function peekSegment()
    : ?string
    {
        return $this->stack->peek();
    }

    public function takeSegment()
    : ?string
    {
        return $this->stack->take();
    }

    public function hasMoreSegments()
    : bool
    {
        return $this->stack->peek() !== null;
    }

    public function remainingSegments()
    : array
    {
        return $this->stack->remaining();
    }

    public function queryValue(string $canonicalKey)
    : ?string {
        return null;
    }

    public function stack()
    : RouteStack
    {
        return $this->stack;
    }
}