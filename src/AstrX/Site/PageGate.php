<?php
declare(strict_types=1);

namespace AstrX\Site;

use AstrX\Admin\AdminPermission;
use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Auth\RequiresPermission;
use AstrX\Config\Config;
use AstrX\Http\HttpStatus;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleRegistry;
use AstrX\Page\ModulePageGuard;
use AstrX\Page\Page;
use AstrX\Page\PageGuard;
use AstrX\Page\PageHandler;

/**
 * Decides whether the resolved page may be served to this visitor, BEFORE its
 * controller is constructed:
 *
 *   1. a hidden page is the themed 404 — except for admins, who see it;
 *   2. module guards: a page owned by a disabled module, or vetoed by an
 *      enabled module's own guard, is the themed 404;
 *   3. the entry permission (page tree + the controller's
 *      #[RequiresPermission] + the Admin* naming rule): a visitor without it
 *      is sent to the login page.
 *
 * (The panic lockdown is the fourth gate; ContentManager applies it next.)
 */
final class PageGate
{
    public function __construct(
        private readonly Injector      $injector,
        private readonly Config        $config,
        private readonly Translator    $translator,
        private readonly Gate          $gate,
        private readonly ControllerMap $controllers,
    ) {}

    /**
     * Gates 1 and 2. Returns the page to serve (the error page, with a 404
     * status, when one fired) and whether an admin is viewing a hidden page.
     * Each swap re-registers the page with the injector, as later code (and
     * module guards built through the injector) receive it from there.
     *
     * @return array{Page, bool}
     */
    public function admit(Page $page, PageHandler $pageHandler): array
    {
        $adminViewingHidden = $page->hidden && $this->gate->can(AdminPermission::ACCESS);
        if (!$adminViewingHidden && $page->hidden) {
            http_response_code(HttpStatus::NOT_FOUND->value);
            $page = $this->errorPage($pageHandler) ?? $page;
            $this->injector->setClass($page);
        }

        // ── Module page guards ────────────────────────────────────────────────
        // Enabled modules may veto a page — swap it for the themed error page
        // instead of running its controller. The honeypot (WORDING_TRAP, hidden=0
        // so it can serve its maze to bots when enabled) uses this to look exactly
        // like a missing page while disabled. Core names no module or page id
        // here: it runs whatever guards the ENABLED modules contribute (see
        // ModuleRegistry). Same swap the hidden-page branch above performs.
        $registryResult = $this->injector->getClass(ModuleRegistry::class);
        if ($registryResult->isOk()) {
            $registry = $registryResult->unwrap();
            if ($registry instanceof ModuleRegistry) {
                // The CMS owns the guard interface; the registry only hands out
                // what enabled modules declared. Module ownership goes first.
                $guards = [new ModulePageGuard($registry)];
                foreach ($registry->enabledGuards() as $contributed) {
                    if ($contributed instanceof PageGuard) {
                        $guards[] = $contributed;
                    }
                }
                foreach ($guards as $guard) {
                    if (!$guard->shouldSwapToError($page)) {
                        continue;
                    }
                    http_response_code(HttpStatus::NOT_FOUND->value);
                    $page = $this->errorPage($pageHandler) ?? $page;
                    $this->injector->setClass($page);
                    break;
                }
            }
        }

        return [$page, $adminViewingHidden];
    }

    /** Gate 3: may this visitor enter the page at all? */
    public function mayEnter(Page $page): bool
    {
        $required = self::requiredPermission(
            $page,
            $page->controller ? $this->controllers->classFor($page->fileName) : null,
        );
        return $required === null || $this->gate->can($required);
    }

    /** Where a visitor who may not enter is sent: the login page, in the current locale. */
    public function loginUrl(): string
    {
        // resolve the login URL properly through the translator
        $loginSlug  = $this->translator->t('WORDING_LOGIN', fallback: 'login');
        $locale     = $this->translator->getLocale();
        $basePath   = $this->config->getConfigString('Routing', 'base_path', '/');
        $urlRewrite = $this->config->getConfigBool('Routing', 'url_rewrite', true);
        $localeKey  = $this->config->getConfigString('Routing', 'locale_key', 'lang');
        if ($urlRewrite) {
            $loginUrl = rtrim($basePath, '/') . '/' . $locale . '/' . $loginSlug;
        } else {
            $loginUrl = $basePath . '?' . $localeKey . '=' . $locale . '&page=' . $loginSlug;
        }
        return $loginUrl;
    }

    /** The configured error page, or null if it cannot be loaded. */
    private function errorPage(PageHandler $pageHandler): ?Page
    {
        $errorUrlId = $this->config->getConfig('ContentManager', 'error_page_url_id', 'WORDING_ERROR');
        $errorUrlId = is_string($errorUrlId) ? $errorUrlId : 'WORDING_ERROR';
        $eid = $pageHandler->getPageIdFromUrlId($errorUrlId);
        return $eid !== null ? $pageHandler->getPage($eid) : null;
    }

    /**
     * The permission a visitor must hold to ENTER this page, or null when the
     * page is open to everyone.
     *
     * Three sources, checked in order, deliberately overlapping:
     *
     *   1. The page tree. Every descendant of the admin root requires
     *      ADMIN_ACCESS. file_name is used rather than url_id because file_name
     *      is never translated and is not editable through the Pages UI.
     *
     *   2. A #[RequiresPermission] attribute on the controller class (or on any
     *      of its parents).
     *
     *   3. The Admin*Controller naming the reflection router already keys
     *      dispatch on.
     *
     * 2 and 3 exist because 1 alone is DATA: `GET /en/admin-content` with no
     * session used to reach AdminContentController::handle() and render the
     * admin shell under the controller's own 403, purely because
     * migrate_zz_content_module.sql inserted the page's self row in
     * `page_closure` and not the WORDING_ADMIN parent row. A missing row is now
     * a cosmetic bug (the template resolves to the wrong include path) instead
     * of a silently disabled gate — no migration can unlock an Admin*Controller.
     *
     * This is the coarse "may you open this page" gate only. Controllers keep
     * their finer checks: AdminConfigSystemController still answers 403 to a MOD
     * who holds ADMIN_ACCESS but not ADMIN_CONFIG_SYSTEM.
     */
    public static function requiredPermission(Page $page, ?string $controllerClass): ?Permission
    {
        if ($page->fileName === 'admin'
            || array_any($page->ancestors, static fn($a): bool => $a['file_name'] === 'admin')
        ) {
            return AdminPermission::ACCESS;
        }

        if (!$page->controller) {
            return null;
        }

        $fqcn = $controllerClass;
        if ($fqcn === null || !class_exists($fqcn)) {
            return null;
        }

        // Walk the class and its parents: getAttributes() does not inherit, so a
        // shared admin base class would otherwise declare a gate that none of its
        // subclasses enforced.
        for ($rc = new \ReflectionClass($fqcn); $rc !== false; $rc = $rc->getParentClass()) {
            foreach ($rc->getAttributes(RequiresPermission::class) as $attribute) {
                return $attribute->newInstance()->permission;
            }
        }

        // 'AstrX\Content\Controller\AdminContentController' → short name starts with
        // 'Admin'. Every one of the 33 Admin*Controller classes is an admin
        // surface; the moderator panels that are deliberately NOT admin
        // (ChatAdminController → CHAT_MODERATE, BoardModController →
        // BOARD_MODERATE) do not match this prefix and keep their own gates.
        $short = substr($fqcn, strrpos($fqcn, '\\') + 1);
        return str_starts_with($short, 'Admin') ? AdminPermission::ACCESS : null;
    }
}
