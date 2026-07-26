<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Page\Page;

/**
 * A module's veto over a page. Before running a page's controller, ContentManager
 * asks every ENABLED module's guards whether the page should be swapped for the
 * themed error page instead — e.g. the honeypot ({@see \AstrX\BotTrap}) must be
 * indistinguishable from a missing page while it is disabled. Core runs whatever
 * guards the enabled modules contribute and never names a module or a page id.
 */
interface PageGuard
{
    /**
     * Return true to swap this page for the site's error page (a themed 404),
     * false to let it run normally.
     */
    public function shouldSwapToError(Page $page): bool;
}
