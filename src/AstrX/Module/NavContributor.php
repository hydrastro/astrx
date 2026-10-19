<?php
declare(strict_types=1);

namespace AstrX\Module;

/**
 * A module's contribution to the site-wide template context: vars its layout
 * partials need on every page (e.g. the bot-trap honeypot link's URL). Wired
 * by the manifest's `nav` key; {@see \AstrX\Site\DefaultTemplateContext}
 * merges the vars from every ENABLED module (a disabled one's `nav_defaults`
 * instead) and never names a module itself.
 *
 * Which partials a module adds to the layout is declared separately, by the
 * manifest's `layout` key ({@see ModuleRegistry::layoutPartials()}).
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
