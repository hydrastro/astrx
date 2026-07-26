<?php
declare(strict_types=1);

namespace AstrX\BotTrap;

use AstrX\Module\PageGuard;
use AstrX\Page\Page;

/**
 * Keeps the honeypot indistinguishable from a missing page while it is disabled.
 *
 * The trap page (WORDING_TRAP) is hidden=0 so it can serve its maze to bots when
 * enabled; while DISABLED it must look like any other missing URL, so this guard
 * tells ContentManager to swap in the normal themed error page instead of running
 * the trap controller. Registered via ModuleRegistry only when the bottrap module
 * is on, so core carries no reference to the trap page id or config.
 */
final class BotTrapPageGuard implements PageGuard
{
    public function __construct(
        private readonly BotTrapConfig $config,
    ) {}

    public function shouldSwapToError(Page $page): bool
    {
        return $page->urlId === 'WORDING_TRAP' && !$this->config->enabled();
    }
}
