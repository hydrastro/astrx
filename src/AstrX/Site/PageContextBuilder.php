<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Config\Config;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Navbar\NavbarHandler;
use AstrX\Page\Diagnostic\PageHiddenNoticeDiagnostic;
use AstrX\Page\Page;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Template\TemplateVars;

/**
 * Prepares everything a page's controller and template read: the page's
 * translation domains (configured extras, then ancestors, then the page
 * itself, so the most specific wins), the request's DefaultTemplateContext
 * (bound as TemplateVars for feature controllers) and the three DB-driven
 * navbars.
 */
final class PageContextBuilder
{
    public function __construct(
        private readonly Injector             $injector,
        private readonly Config               $config,
        private readonly Translator           $translator,
        private readonly DiagnosticsCollector $collector,
    ) {}

    /** @return DefaultTemplateContext|null null when the context cannot be built (500) */
    public function build(Page $page, bool $adminViewingHidden): ?DefaultTemplateContext
    {
        // Load lang files for the current page and all its ancestors (bottom-up order,
        // so more-specific pages override ancestor values where keys overlap).
        // e.g. login → ancestor 'user' → loads User.en.php automatically.
        // Must happen before DefaultTemplateContext::buildBase() so that title/description
        // and keyword translations are already in the catalog.
        $extraDomains = $this->config->getConfig('ContentManager', 'extra_lang_domains', []);
        if (is_array($extraDomains)) {
            foreach ($extraDomains as $domain) {
                if (is_string($domain) && preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $domain) === 1) {
                    $this->translator->load($domain);
                }
            }
        }

        // Ancestors first (most general → most specific), then the page itself last
        // so the page's own domain wins on any key conflict.
        $ancestorFileNames = [];
        foreach ($page->ancestors as $ancestor) {
            $fn = $ancestor['file_name'];
            if ($fn !== '' && $fn !== $page->fileName) {
                $ancestorFileNames[] = ucfirst($fn);
            }
        }
        foreach (array_unique($ancestorFileNames) as $ancestorDomain) {
            $this->translator->load($ancestorDomain);
        }
        $this->translator->load(ucfirst($page->fileName));

        $ctxResult = $this->injector->createClass(DefaultTemplateContext::class)
            ->drainTo($this->collector);

        if (!$ctxResult->isOk()) {
            return null;
        }

        /** @var DefaultTemplateContext $ctx */
        $ctx = $ctxResult->unwrap();
        // Feature controllers ask for the TemplateVars interface; point it at
        // THIS request's context (bind() copies the instance registered now).
        $this->injector->bind(TemplateVars::class, DefaultTemplateContext::class);
        $ctx->buildBase($page);
        if ($adminViewingHidden) {
            $this->collector->emit(new PageHiddenNoticeDiagnostic(
                                       'astrx.content/page_hidden', DiagnosticLevel::DEBUG
                                   ));
        }

        // Populate navbar. Failure is non-fatal — the template renders with an
        // empty navbar rather than taking down the whole page.
        $navbarId = $this->config->getConfigInt('ContentManager', 'public_navbar_id', 1);
        $navbarResult = $this->injector->createClass(NavbarHandler::class)
            ->drainTo($this->collector);
        if ($navbarResult->isOk()) {
            /** @var NavbarHandler $navbarHandler */
            $navbarHandler = $navbarResult->unwrap();
            $ctx->set('navbar', $navbarHandler->getNavbarEntries($navbarId, $page->ancestors, $page->fileName));

            // User navbar (id=2) and admin navbar (id=3) are also DB-driven.
            // DefaultTemplateContext::finalise() reads these from ctx vars instead of
            // hardcoding the entries, so the admin can manage them via the navbar editor.
            $userNavbarId  = $this->config->getConfigInt('ContentManager', 'user_navbar_id',  2);
            $adminNavbarId = $this->config->getConfigInt('ContentManager', 'admin_navbar_id', 3);
            $ctx->set('db_user_nav',  $navbarHandler->getNavbarEntries($userNavbarId,  $page->ancestors, $page->fileName));
            $ctx->set('db_admin_nav', $navbarHandler->getNavbarEntries($adminNavbarId, $page->ancestors, $page->fileName));
        }

        return $ctx;
    }
}
