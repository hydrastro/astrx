<?php

declare(strict_types = 1);

namespace AstrX\Routing;

/**
 * Unified input stream for controller routing.
 * Controllers should:
 * - consumeOptionalPrefix([...])
 * - then consumeRequired([...])
 * Implementations:
 * - RewriteRouteInput: consumes path segments
 * - QueryRouteInput: reads query params using canonical->external alias maps
 */
interface RouteInput
{
    public function mode()
    : UrlMode;

    /** Peek next raw segment (rewrite only). Query mode returns null. */
    public function peekSegment()
    : ?string;

    /** Consume next segment (rewrite only). Query mode returns null. */
    public function takeSegment()
    : ?string;

    /** True if more segments remain (rewrite only). */
    public function hasMoreSegments()
    : bool;

    /** Return remaining segments (rewrite only). */
    public function remainingSegments()
    : array;

    /**
     * Resolve canonical key to value, for query mode.
     * In rewrite mode this returns null (controllers shouldn’t use it directly).
     */
    public function queryValue(string $canonicalKey)
    : ?string;
}