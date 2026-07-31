<?php
declare(strict_types=1);

namespace AstrX\Module;

use AstrX\Page\Page;

/**
 * Core guard: hide a page whose owning module is disabled.
 *
 * Every page carries a `module` tag ('' = core / always-on; see the
 * migrate_module_page_ownership migration). When that module is switched off in
 * Modules.config.php, this guard tells ContentManager to swap the page for the
 * themed error page — so a disabled module's pages 404 exactly like a missing
 * page. Generic: it reads the page's own `module` value against the registry's
 * enabled state and names no specific module.
 */
final class ModulePageGuard implements PageGuard
{
    public function __construct(
        private readonly ModuleRegistry $registry,
    ) {}

    public function shouldSwapToError(Page $page): bool
    {
        $raw = strtolower(trim($page->module));
        if ($raw === '') {
            return false; // core / always-on page
        }
        // Resolve page.module to the canonical manifest key (case/whitespace-
        // insensitive) so a drifted value ('Chat' vs 'chat', stray spaces) can't
        // slip past the gate. A module-owned page whose module is NOT a recognised
        // manifest key is hidden (fail closed) rather than shown open (R4-21).
        $canonical = null;
        foreach ($this->registry->moduleKeys() as $k) {
            if (strtolower(trim($k)) === $raw) {
                $canonical = $k;
                break;
            }
        }
        if ($canonical === null) {
            return true; // unknown module owner → fail closed
        }
        return !$this->registry->enabled($canonical);
    }
}
