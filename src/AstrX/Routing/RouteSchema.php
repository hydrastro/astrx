<?php

declare(strict_types = 1);

namespace AstrX\Routing;

/**
 * Enforces local rule: optional prefix first, then required tail.
 * Consumption writes values into RouteState as canonical keys.
 */
final class RouteSchema
{
    /**
     * Consume optional prefix keys.
     * In rewrite mode: consumes one segment per key if segment exists.
     * In query mode: reads from query aliases without consuming anything.
     *
     * @param list<string> $keys canonical keys
     */
    public static function consumeOptionalPrefix(
        RouteInput $in,
        RouteState $state,
        array $keys
    )
    : void {
        if ($in->mode() === UrlMode::REWRITE) {
            foreach ($keys as $k) {
                $seg = $in->peekSegment();
                if ($seg === null) {
                    $state->set($k, null);
                    continue;
                }
                // optional prefix: consumer decides to take; default behavior is "take if present"
                $state->set($k, (string)$in->takeSegment());
            }

            return;
        }

        // QUERY mode
        foreach ($keys as $k) {
            $state->set($k, $in->queryValue($k));
        }
    }

    /**
     * Consume required keys.
     * In rewrite mode: MUST consume N segments; missing => null.
     * In query mode: MUST read values; missing => null (controller decides fatality).
     *
     * @param list<string> $keys canonical keys
     */
    public static function consumeRequired(
        RouteInput $in,
        RouteState $state,
        array $keys
    )
    : void {
        if ($in->mode() === UrlMode::REWRITE) {
            foreach ($keys as $k) {
                $state->set($k, $in->takeSegment());
            }

            return;
        }

        foreach ($keys as $k) {
            $state->set($k, $in->queryValue($k));
        }
    }
}