<?php
declare(strict_types=1);

namespace AstrX\Template;

/**
 * Write side of a template variable bag.
 *
 * Feature code that contributes variables to a page (e.g. Chat\ChatNav filling
 * the chat toolbar) type-hints this instead of the application's concrete page
 * context (Site\DefaultTemplateContext), so features do not depend on the
 * application layer.
 */
interface TemplateVars
{
    public function set(string $key, mixed $value): void;
}
