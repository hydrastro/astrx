<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Admin\AdminPermission;
use AstrX\Auth\Gate;
use AstrX\Config\Config;
use AstrX\Database\ConnectionFactory;
use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\DiagnosticRenderer;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Module\ModuleRegistry;
use AstrX\Page\PageHandler;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\RouteParser;
use AstrX\Session\PrgHandler;
use AstrX\Site\ControllerDispatcher;
use AstrX\Site\ControllerMap;
use AstrX\Site\ErrorResponder;
use AstrX\Site\PageContextBuilder;
use AstrX\Site\PageGate;
use AstrX\Site\PageRenderer;
use AstrX\Site\PageResolver;
use AstrX\Site\PanicLockdown;
use AstrX\Site\PrgIntake;
use AstrX\Site\RequestIdentity;
use AstrX\Site\SecurityHeaders;
use AstrX\Site\SessionStarter;
use AstrX\Site\SiteFlags;
use PDO;

/**
 * Runs one web request through the site, in order:
 *
 *   route (RouteParser) → database (ConnectionFactory) → session
 *   (SessionStarter) → caller identity (RequestIdentity) → PRG intake
 *   (PrgIntake) → page (PageResolver) → gates (PageGate, then the panic
 *   lockdown) → page context (PageContextBuilder) → controllers
 *   (ControllerDispatcher) → security headers → response (PageRenderer).
 *
 * Each step lives in its own class; this one only sequences them and turns a
 * failed step into the themed error page (ErrorResponder).
 */
final class ContentManager
{
    /** page file_name → controller class; see controllerMap(). */
    private ?ControllerMap $controllerMap = null;

    public function __construct(
        private readonly Injector $injector,
        private readonly Config $config,
        private readonly DiagnosticsCollector $collector,
        private readonly ModuleLoader $moduleLoader,
        private readonly Translator $translator,
        private readonly Gate $gate,
    ) {}

    public function init(): void
    {
        $astrxRequestStarted = microtime(true);
        $this->config->loadModuleConfig('Routing');
        $this->config->loadModuleConfig('Session');
        $this->config->loadModuleConfig('ContentManager');
        $this->config->loadModuleConfig('PDO');

        // Routing keys this method still needs after parsing (the parser reads
        // its own configuration).
        $sessionUseCookies = $this->config->getConfigBool('Session', 'use_cookies', true);
        $sessionKey        = $this->config->getConfigString('Routing', 'session_key', 'sid');
        $pageKey           = $this->config->getConfigString('Routing', 'page_key', 'page');
        $defaultPageToken  = $this->config->getConfigString('Routing', 'default_page', 'WORDING_MAIN');

        $prgTokenKey = $this->config->getConfig('Session', 'prg_token_key', 'prg');
        assert(is_string($prgTokenKey));

        $prgTokenRegex = $this->config->getConfig('Session', 'prg_token_regex', '/^[\da-fA-F]{64}$/');
        assert(is_string($prgTokenRegex));
        assert(@preg_match($prgTokenRegex, '') !== false);

        $requestResult = Request::fromGlobals()->drainTo($this->collector);
        $request = $requestResult->unwrap(); // always ok — fromGlobals never returns err
        $this->injector->setClass($request);

        $current = new CurrentUrl();

        $uriRaw = $_SERVER['REQUEST_URI'] ?? '/';
        $route  = RouteParser::fromConfig($this->config)
            ->parse($request, $current, is_string($uriRaw) ? $uriRaw : '/');
        $locale       = $route->locale;
        $sidCandidate = $route->sidCandidate;
        $pageToken    = $route->pageToken;

        // Register the now-populated CurrentUrl so that injectable services
        // (e.g. NavbarHandler) can receive the current locale, session id, etc.
        $this->injector->setClass($current);

        $this->translator->setLocale($locale);
        $this->moduleLoader->setLocale($locale);

        $pagesDomain = $this->config->getConfig('ContentManager', 'pages_lang_domain', 'pages');
        assert(is_string($pagesDomain));
        $this->translator->load($pagesDomain);

        // Navbar display labels — loaded globally so NavbarHandler can resolve
        // WORDING_ entry names regardless of which page is being rendered.
        $navbarDomain = $this->config->getConfig('ContentManager', 'navbar_lang_domain', 'Navbar');
        assert(is_string($navbarDomain));
        $this->translator->load($navbarDomain);

        // Diagnostic messages — loaded into DiagnosticRenderer's own catalog,
        // NOT into the Translator, to prevent the recursion where rendering a
        // MissingTranslationDiagnostic would emit another MissingTranslationDiagnostic.
        $diagnosticsDomain = $this->config->getConfigString('ContentManager', 'diagnostics_lang_domain', 'Diagnostics');
        $rendererResult = $this->injector->getClass(DiagnosticRenderer::class);
        if ($rendererResult->isOk()) {
            /** @var DiagnosticRenderer $renderer */
            $renderer = $rendererResult->unwrap();
            $renderer->load($diagnosticsDomain);
        }

        // The database is the framework's single hard dependency: sessions,
        // pages, the navbar, users and the themed error page all read it. Stop
        // the request here rather than letting the dozen createClass() calls
        // below each fail with its own unresolvable-parameter diagnostic and
        // bury the one that says why. 503, not 500: nothing is wrong with the
        // request, the service is not available to answer it.
        $pdoResult = (new ConnectionFactory($this->config))->connect()->drainTo($this->collector);
        if (!$pdoResult->isOk()) {
            $this->renderError(HttpStatus::SERVICE_UNAVAILABLE);
            return;
        }
        $this->injector->setClass($pdoResult->unwrap());

        // Bearer/API requests must be STATELESS (see SessionStarter); the same
        // flag later decides whether to authenticate by API key or revalidate a
        // cookie session.
        $bearerPresent = (($request->bearerToken() ?? '') !== '');
        $sessionStarter = new SessionStarter($this->injector, $this->config, $this->collector);
        if (!$sessionStarter->start($request, $sidCandidate, $bearerPresent)) {
            $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
            return;
        }

        $sid = (string) session_id();
        $current->set($sessionKey, $sid);
        $request->query()->set($sessionKey, $sid);

        // ── Who is calling: API key, or a re-validated cookie session ─────────
        if (!(new RequestIdentity($this->injector, $this->collector))->establish($request, $bearerPresent)) {
            $this->renderError(HttpStatus::SERVICE_UNAVAILABLE);
            return;
        }

        $pageToken = ($pageToken === '' ? $defaultPageToken : $pageToken);
        $current->set($pageKey, $pageToken);
        $request->query()->set($pageKey, $pageToken);

        $prgResult = $this->injector->getClass(PrgHandler::class);
        if (!$prgResult->isOk()) {
            $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
            return;
        }

        /** @var PrgHandler $prgHandler */
        $prgHandler = $prgResult->unwrap();

        $prgIntake = new PrgIntake($this->collector);
        if ($prgIntake->isPrgPost($request)) {
            $prgError = $prgIntake->redirect(
                $request,
                $prgHandler,
                fn(): bool => $this->panicActive() && $this->gate->cannot(AdminPermission::PANIC),
            );
            if ($prgError !== null) {
                $this->renderError($prgError);
                return;
            }
            exit;
        }
        $prgIntake->restoreUploads($request, $prgHandler);

        $pageHandlerResult = $this->injector->createClass(PageHandler::class)
            ->drainTo($this->collector);

        if (!$pageHandlerResult->isOk()) {
            $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
            return;
        }

        /** @var PageHandler $pageHandler */
        $pageHandler = $pageHandlerResult->unwrap();

        $page = (new PageResolver($this->injector, $this->config, $this->translator))
            ->resolve($pageHandler, $pageToken);
        $this->injector->setClass($page);

        // ── Page gates: hidden page, module guards, entry permission ──────────
        // All run BEFORE the controller is constructed, so a visitor who may not
        // enter a page never reaches the code that serves it (see PageGate).
        $pageGate = new PageGate($this->injector, $this->config, $this->translator, $this->gate, $this->controllerMap());
        [$page, $adminViewingHidden] = $pageGate->admit($page, $pageHandler);
        if (!$pageGate->mayEnter($page)) {
            Response::redirect($pageGate->loginUrl())->send()->drainTo($this->collector);
            exit;
        }

        // ── Panic / lockdown gate ─────────────────────────────────────────────
        // When the operator flips panic on, every NON-admin request is sealed off.
        // This deliberately runs at the SETTLING GET / dispatch point, where $page
        // is the REAL executor: for a PRG mutation that is the prg_id's target (the
        // getUrl redirect above), NOT the POST landing URL — which is exactly the
        // hole the earlier intake-level gate left open (exempt /login as a landing,
        // mutate elsewhere). API controllers run through this same dispatch, so the
        // API path is covered here too. Login + captcha + the error page stay open
        // so an admin can still authenticate and lift the lockdown.
        //
        // Key on ADMIN_PANIC — the permission that ARMS/DISARMS the lockdown — NOT
        // ADMIN_ACCESS, which MOD also holds. Bypass ⟺ can-disarm: only an operator
        // who could lift the lockdown is exempt from it, so a compromised MOD is
        // sealed like every other non-operator.
        if ($this->panicActive()
            && $this->gate->cannot(AdminPermission::PANIC)
            && !PanicLockdown::isExempt($page)) {
            $this->renderPanicLockdown($request);
            return;
        }

        $ctx = (new PageContextBuilder($this->injector, $this->config, $this->translator, $this->collector))
            ->build($page, $adminViewingHidden);
        if ($ctx === null) {
            $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
            return;
        }

        // Comments stay closed to anyone a panic lockdown seals, even on a page
        // the lockdown leaves open (an exempt page with comments enabled would
        // otherwise still accept a guest comment).
        $dispatchError = (new ControllerDispatcher($this->injector, $this->translator, $this->collector, $this->controllerMap()))
            ->dispatch($page, $this->panicActive() && $this->gate->cannot(AdminPermission::PANIC));
        if ($dispatchError !== null) {
            $this->renderError($dispatchError);
            return;
        }

        // ── Response phase ────────────────────────────────────────────────────
        // Baseline security headers go out ONCE, HERE, before any branch that can
        // emit a body — the fragment branch, the API branch, the HTML branch and
        // the template=0 fall-through all sit below this line. They used to be
        // emitted only on the HTML branch, so
        // `curl -H 'X-AstrX-JS-Browser: 1' http://host/en/main` returned a full
        // HTML document with NO Content-Security-Policy and NO X-Frame-Options —
        // and CSP `default-src 'none'` is what neutralises every {{&raw}} sink in
        // the codebase. Emitting first means no early `return` added below can
        // drop them; a branch that needs something different overrides that one
        // header (header() replaces by default) instead of having to opt in.
        //
        // Controllers that stream their own bytes (captcha frame/image, media,
        // avatars) exit() inside handle() and never reach this line, so the
        // captcha iframe keeps its deliberate X-Frame-Options: SAMEORIGIN and is
        // not sealed off by this response's frame-ancestors 'none'.
        $this->emitSecurityHeaders();

        $renderError = (new PageRenderer($this->injector, $this->config, $this->collector))
            ->render($page, $ctx, $request, $locale, $astrxRequestStarted);
        if ($renderError !== null) {
            $this->renderError($renderError);
        }
    }

    // =========================================================================
    // Controller map
    // =========================================================================

    /** Built once per request from ModuleRegistry (CORE only if it is unavailable). */
    private function controllerMap(): ControllerMap
    {
        if ($this->controllerMap !== null) {
            return $this->controllerMap;
        }
        $registryResult = $this->injector->getClass(ModuleRegistry::class);
        $registry = $registryResult->isOk() ? $registryResult->unwrap() : null;
        $this->controllerMap = $registry instanceof ModuleRegistry
            ? ControllerMap::forApplication($registry)
            : new ControllerMap(ControllerMap::CORE);
        return $this->controllerMap;
    }

    // =========================================================================
    // Response helpers (see Site\ErrorResponder, SecurityHeaders, PanicLockdown)
    // =========================================================================

    /** End the request with the themed error page (or the failsafe). */
    private function renderError(HttpStatus $status): void
    {
        (new ErrorResponder(
            $this->injector,
            $this->config,
            $this->translator,
            $this->collector,
            $this->securityHeaders(),
            $this->siteFlags(),
            $this->controllerMap(),
        ))->respond($status);
    }

    /** The operator switches in site_config, read through this request's connection (if any). */
    private function siteFlags(): SiteFlags
    {
        $pdoResult = $this->injector->getClass(PDO::class, false);
        $pdo = $pdoResult->isOk() ? $pdoResult->unwrap() : null;
        return new SiteFlags($pdo instanceof PDO ? $pdo : null);
    }

    private function securityHeaders(): SecurityHeaders
    {
        return new SecurityHeaders($this->config);
    }

    private function emitSecurityHeaders(): void
    {
        $this->securityHeaders()->emit($this->siteFlags());
    }

    private function panicActive(): bool
    {
        return $this->siteFlags()->panicActive();
    }

    private function renderPanicLockdown(Request $request): void
    {
        (new PanicLockdown($this->securityHeaders()))->respond($request, $this->siteFlags());
    }
}
