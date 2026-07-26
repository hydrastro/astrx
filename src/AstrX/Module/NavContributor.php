<?php
declare(strict_types=1);

namespace AstrX\Module;

/**
 * A module's contribution to the site-wide template context: the navigation
 * partial slots it registers and any header/footer vars it sets (e.g. the
 * bot-trap honeypot link). Implemented by a small class inside each module and
 * wired in {@see ModuleRegistry}; {@see \AstrX\Template\DefaultTemplateContext}
 * merges the vars from every ENABLED module and never names a module itself.
 *
 * Returned keys are ordinary template context vars. A partial slot is a var
 * whose value is a partial path string, e.g. `['board_nav' => 'partials/board_nav']`
 * — default.html renders `{{> board_nav}}` by resolving that var, so a slot that
 * is absent (module disabled) simply renders nothing.
 */
interface NavContributor
{
    /**
     * Context vars this module contributes when enabled.
     *
     * @return array<string,mixed>
     */
    public function vars(): array;
}
