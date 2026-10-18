<?php
declare(strict_types=1);

namespace AstrX\Template;

/**
 * Write side of a page's template variable bag.
 *
 * Feature code that contributes variables to a page — feature controllers,
 * helpers such as Chat\ChatNav — type-hints this instead of the application's
 * concrete page context (Site\DefaultTemplateContext), so features do not
 * depend on the application layer. The application binds its context to this
 * interface for each request.
 */
interface TemplateVars
{
    /**
     * Set a variable. The scope decides which renderings may see it: the
     * default WEB_ONLY keeps it out of every JSON response, so exposing data
     * to machine clients is always an explicit choice at the call site.
     */
    public function set(string $key, mixed $value, ContextScope $scope = ContextScope::WEB_ONLY): void;

    /**
     * Shorthand for set($key, $value, ContextScope::SHARED): also exposed to
     * machine clients (the JSON rendering of the page). Only for values safe
     * to publish.
     */
    public function setShared(string $key, mixed $value): void;
}
