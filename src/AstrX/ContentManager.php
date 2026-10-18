<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
use AstrX\Config\Config;
use AstrX\Database\ConnectionFactory;
use AstrX\Kernel\Controller;
use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Module\ModuleRegistry;
use AstrX\Page\ModulePageGuard;
use AstrX\Page\PageGuard;
use AstrX\Navbar\NavbarHandler;
use AstrX\Page\Page;
use AstrX\Page\Diagnostic\ControllerMissingDiagnostic;
use AstrX\Page\Diagnostic\PageHiddenNoticeDiagnostic;
use AstrX\Page\PageHandler;
use AstrX\Result\Diagnostics;
use AstrX\Result\DiagnosticLevel;
use AstrX\I18n\DiagnosticRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\RouteParser;
use AstrX\Session\PrgHandler;
use AstrX\User\UserSession;
use AstrX\Auth\RequiresPermission;
use AstrX\Site\Controller\CommentController;
use AstrX\Site\ControllerMap;
use AstrX\Site\ErrorResponder;
use AstrX\Site\PanicLockdown;
use AstrX\Site\PrgIntake;
use AstrX\Site\RequestIdentity;
use AstrX\Site\SecurityHeaders;
use AstrX\Site\SessionStarter;
use AstrX\Site\SiteFlags;
use AstrX\Site\DefaultTemplateContext;
use AstrX\Template\TemplateEngine;
use AstrX\Template\TemplateVars;
use PDO;
use function AstrX\Support\langDir;
use AstrX\Admin\AdminPermission;

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

        // Bearer auth: if the request carries an Authorization: Bearer token,
        // resolve it via ApiKeyService BEFORE the regular session machinery
        // runs. A successful API-key auth bootstraps UserSession as that user
        // for the lifetime of this request. This is independent of the /api/
        // URL marker — a bearer token works on the regular web URL too,
        // although in practice only API callers will use it.
        // Bearer/API-key auth is bootstrapped AFTER session_start (below), once
        // $_SESSION actually exists — doing it here (before session_start) wrote
        // into a $_SESSION that session_start() then re-initialised, so the entire
        // API-auth path was silently inert (R4-13).

        $this->translator->setLocale($locale);
        $this->moduleLoader->setLocale($locale);

        $pagesDomain = $this->config->getConfig('ContentManager', 'pages_lang_domain', 'pages');
        assert(is_string($pagesDomain));
        $this->translator->loadDomain(langDir(), $pagesDomain);

        // Navbar display labels — loaded globally so NavbarHandler can resolve
        // WORDING_ entry names regardless of which page is being rendered.
        $navbarDomain = $this->config->getConfig('ContentManager', 'navbar_lang_domain', 'Navbar');
        assert(is_string($navbarDomain));
        $this->translator->loadDomain(langDir(), $navbarDomain);

        // Diagnostic messages — loaded into DiagnosticRenderer's own catalog,
        // NOT into the Translator, to prevent the recursion where rendering a
        // MissingTranslationDiagnostic would emit another MissingTranslationDiagnostic.
        $diagnosticsDomain = $this->config->getConfigString('ContentManager', 'diagnostics_lang_domain', 'Diagnostics');
        $rendererResult = $this->injector->getClass(DiagnosticRenderer::class);
        if ($rendererResult->isOk()) {
            /** @var DiagnosticRenderer $renderer */
            $renderer = $rendererResult->unwrap();
            $renderer->loadDomain(langDir(), $diagnosticsDomain);
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

        $page = $this->resolvePage($pageHandler, $pageToken);
        $this->injector->setClass($page);
        $adminViewingHidden = $page->hidden && $this->gate->can(AdminPermission::ACCESS);
        if (!$adminViewingHidden && $page->hidden) {
            http_response_code(HttpStatus::NOT_FOUND->value);
            $errorUrlId = $this->config->getConfig('ContentManager','error_page_url_id','WORDING_ERROR');
            assert(is_string($errorUrlId));
            $eid  = $pageHandler->getPageIdFromUrlId($errorUrlId);
            $page = ($eid !== null ? $pageHandler->getPage($eid) : null) ?? $page;
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
                    $errorUrlId = $this->config->getConfig('ContentManager', 'error_page_url_id', 'WORDING_ERROR');
                    $errorUrlId = is_string($errorUrlId) ? $errorUrlId : 'WORDING_ERROR';
                    $eid  = $pageHandler->getPageIdFromUrlId($errorUrlId);
                    $page = ($eid !== null ? $pageHandler->getPage($eid) : null) ?? $page;
                    $this->injector->setClass($page);
                    break;
                }
            }
        }

        // ── Page entry guard ──────────────────────────────────────────────────────
        // Runs BEFORE the controller is constructed or handle() is called, so a
        // visitor who may not enter a page never reaches the code that serves it.
        // requiredPagePermission() merges two independent sources — the page tree
        // (data) and the controller class (code) — see that method.
        $requiredPermission = self::requiredPagePermission($page, $this->controllerClassFor($page));
        if ($requiredPermission !== null && $this->gate->cannot($requiredPermission)) {
            $loginUrlId = $this->config->getConfig('Routing', 'default_page', 'WORDING_LOGIN');
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
            Response::redirect($loginUrl)->send()->drainTo($this->collector);
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

        // Load lang files for the current page and all its ancestors (bottom-up order,
        // so more-specific pages override ancestor values where keys overlap).
        // e.g. login → ancestor 'user' → loads User.en.php automatically.
        // Must happen before DefaultTemplateContext::buildBase() so that title/description
        // and keyword translations are already in the catalog.
        $langDir = langDir();

        $extraDomains = $this->config->getConfig('ContentManager', 'extra_lang_domains', []);
        if (is_array($extraDomains)) {
            foreach ($extraDomains as $domain) {
                if (is_string($domain) && preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $domain) === 1) {
                    $this->translator->loadDomain($langDir, $domain);
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
            $this->translator->loadDomain($langDir, $ancestorDomain);
        }
        $this->translator->loadDomain($langDir, ucfirst($page->fileName));

        $ctxResult = $this->injector->createClass(DefaultTemplateContext::class)
            ->drainTo($this->collector);

        if (!$ctxResult->isOk()) {
            $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
            return;
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

        if ($page->controller) {
            // Same derivation the entry guard above used, via the shared helper —
            // if the two ever computed a different class name, the guard would be
            // gating a class that dispatch does not run.
            $fqcn = $this->controllerClassFor($page);

            if ($fqcn !== null && class_exists($fqcn)) {
                $controllerResult = $this->injector->createClass($fqcn)
                    ->drainTo($this->collector);

                if (!$controllerResult->isOk()) {
                    $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                    return;
                }

                $controller = $controllerResult->unwrap();
                if ($controller instanceof Controller) {
                    $r = $controller->handle()->drainTo($this->collector);
                    if (!$r->isOk()) {
                        http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
                    }
                }
            } else {
                // The page declares controller=1 but no such controller class
                // exists — a seed/code mismatch (e.g. a config section seeded in
                // tables.sql whose controller was never implemented). Serve a clean
                // themed 404 rather than a raw 500, and return so we don't fall
                // through to a template render that also has no file. The diagnostic
                // keeps the misconfiguration visible in the logs.
                $this->collector->emit(new ControllerMissingDiagnostic(
                    'astrx.content/controller_missing', DiagnosticLevel::WARNING
                ));
                $this->renderError(HttpStatus::NOT_FOUND);
                return;
            }
        }

        // Dispatch the comment controller if comments are enabled on this page.
        // This runs AFTER the main controller so it can see any vars already set.
        // Also honour a panic lockdown for non-admins: the gate above already
        // sealed non-exempt pages, but an exempt page that happened to have
        // comments enabled would otherwise still accept a guest comment — deny it.
        if ($page->comments
            && !($this->panicActive() && $this->gate->cannot(AdminPermission::PANIC))) {
            // Load Comment lang domain — ModuleLoader would look for
            // CommentController.en.php (class short name), not Comment.en.php.
            $this->translator->loadDomain(langDir(), 'Comment');
            $commentFqcn   = CommentController::class;
            if (class_exists($commentFqcn)) {
                $commentResult = $this->injector->createClass($commentFqcn)
                    ->drainTo($this->collector);
                if ($commentResult->isOk()) {
                    $commentController = $commentResult->unwrap();
                    if ($commentController instanceof Controller) {
                        $commentController->handle()->drainTo($this->collector);
                    }
                }
            }
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

        if ($page->template) {
            $engineResult = $this->injector->createClass(TemplateEngine::class)
                ->drainTo($this->collector);

            if (!$engineResult->isOk()) {
                $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                return;
            }

            /** @var TemplateEngine $engine */
            $engine = $engineResult->unwrap();

            $templateName = $page->templateFileName !== ''
                ? $page->templateFileName
                : $this->config->getConfigString('ContentManager', 'default_template', 'default');

            $ctx->resolveUrls();

            if ($page->comments) {
                $ctx->set('page_comments', true);
                $commentsPreResult = $engine->renderTemplate('partials/comments', $ctx->all())
                    ->drainTo($this->collector);
                if ($commentsPreResult->isOk()) {
                    $ctx->set('comments_html', $commentsPreResult->unwrap());
                }
            } else {
                $ctx->set('page_comments', false);
                $ctx->set('comments_html', '');
            }

            $ctx->finalise();

            // ── JS-browser fragment dispatch ────────────────────────────────
            // The /js/ runtime sends X-AstrX-JS-Browser when it browses the
            // canonical PHP pages. In that case we can skip the expensive outer
            // document shell and return only the chrome/content fragments the
            // runtime knows how to transplant (#header, navs, #message_bar,
            // #main, #footer). Normal browsers still receive the full layout.
            if (!$request->isApi() && $this->isJsBrowserContentRequest($request)) {
                $fragmentResult = $engine->renderTemplate('js_fragment', $ctx->all())
                    ->drainTo($this->collector);

                if (!$fragmentResult->isOk()) {
                    $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                    return;
                }

                if (!headers_sent()) {
                    header('Content-Type: text/html; charset=utf-8');
                    header('Cache-Control: private, no-store');
                    header('Vary: X-AstrX-JS-Browser, Accept', false);
                    header('X-AstrX-JS-Browser: fragment');
                    // CSP / Referrer-Policy / nosniff / X-Frame-Options /
                    // Onion-Location already went out with the baseline above.
                    // This fragment is a full HTML document to anything that
                    // navigates to it directly (curl, or a browser following the
                    // URL), so it needs the same strict CSP as the main document;
                    // the JS shell's own, more permissive CSP is emitted by
                    // JsController on the /js/ shell response, not here.
                    $this->emitServerTiming('astrx_fragment', $astrxRequestStarted);
                }

                echo $fragmentResult->unwrap();
                return;
            }

            $renderResult = $engine->renderTemplate($templateName, $ctx->all())
                ->drainTo($this->collector);

            // ── API dispatch ─────────────────────────────────────────────────
            // /api/<page> URLs are served via the JsonRenderer. The page
            // must have api_enabled = 1 — otherwise we return 404 without
            // revealing the page exists.
            if ($request->isApi()) {
                if (!$page->apiEnabled) {
                    $this->collector->emit(new \AstrX\Api\Diagnostic\ApiNotEnabledDiagnostic(
                        'astrx.api/not_enabled',
                        \AstrX\Result\DiagnosticLevel::WARNING,
                    ));
                    http_response_code(404);
                    if (!headers_sent()) {
                        header('Content-Type: application/json; charset=utf-8');
                    }
                    echo json_encode([
                        'ok'     => false,
                        'status' => 404,
                        'error'  => [
                            'id'          => 'astrx.api/not_enabled',
                            'level'       => 'warning',
                            'level_value' => \AstrX\Result\DiagnosticLevel::WARNING->value,
                            'message'     => 'API endpoint not enabled',
                        ],
                        'meta' => [
                            'locale'      => $locale,
                            'page'        => $page->urlId,
                            'diagnostics' => ['total' => 1, 'visible' => 1, 'hidden' => 0],
                        ],
                        'diagnostics' => [[
                            'id'          => 'astrx.api/not_enabled',
                            'level'       => 'warning',
                            'level_value' => \AstrX\Result\DiagnosticLevel::WARNING->value,
                            'message'     => 'API endpoint not enabled',
                        ]],
                    ], JSON_UNESCAPED_SLASHES);
                    return;
                }

                $rendererResult = $this->injector->createClass(\AstrX\Site\JsonRenderer::class)
                    ->drainTo($this->collector);
                if (!$rendererResult->isOk()) {
                    $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                    return;
                }
                /** @var \AstrX\Site\JsonRenderer $jsonRenderer */
                $jsonRenderer = $rendererResult->unwrap();

                $html = $renderResult->isOk() ? (string) $renderResult->unwrap() : '';
                // Try to find the user-session admin flag through the injector
                $isAdmin = false;
                $sessResult = $this->injector->getClass(\AstrX\User\UserSession::class);
                if ($sessResult->isOk()) {
                    /** @var \AstrX\User\UserSession $sess */
                    $sess = $sessResult->unwrap();
                    $isAdmin = $sess->isLoggedIn() && $sess->isAdmin();
                }

                if (!headers_sent()) {
                    $this->emitServerTiming('astrx_api', $astrxRequestStarted);
                }
                $jsonRenderer->emit(
                    ctx:          $ctx,
                    locale:       $locale,
                    pageUrlId:    $page->urlId,
                    renderedHtml: $html,
                    isAdmin:      $isAdmin,
                );
                return;
            }

            // ── HTML dispatch (default) ──────────────────────────────────────
            if (!$renderResult->isOk()) {
                $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                return;
            }

            if (!headers_sent()) {
                $this->emitServerTiming('astrx_html', $astrxRequestStarted);
            }
            // Baseline security headers (CSP etc.) already went out at the top of
            // the response phase, for this branch and every other one.
            echo $renderResult->unwrap();
            return;
        }

        // Fall-through for pages with template=0. The original intent was
        // "no template, no body — emit 204 No Content". But that breaks for
        // controllers that legitimately write raw bytes (PNG image endpoint,
        // JSON, XML feeds, etc.) — downgrading 200→204 with a non-empty body
        // makes browsers either reject the response ("image contains errors")
        // or strip the body entirely (empty iframe).
        //
        // The fix: only downgrade when NOTHING has been emitted. ob_get_length()
        // tells us if any output buffer holds bytes, and headers_sent() catches
        // the case where output went straight to the wire. If either is true,
        // the controller produced a real response and we leave the status alone.
        $hasContent  = headers_sent() || (ob_get_level() > 0 && ob_get_length() > 0);
        $currentCode = http_response_code();
        if (!$hasContent && ($currentCode === false || $currentCode === 200)) {
            http_response_code(HttpStatus::NO_CONTENT->value);
        }
    }

    // =========================================================================
    // Page entry guard
    // =========================================================================

    /**
     * The controller class that serves this page, from the explicit map
     * (Site\ControllerMap: core pages + module manifests), or null when the
     * page has no controller or none is mapped. Callers class_exists() it.
     *
     * @return class-string|null
     */
    private function controllerClassFor(Page $page): ?string
    {
        if (!$page->controller) {
            return null;
        }
        return $this->controllerMap()->classFor($page->fileName);
    }

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
    private static function requiredPagePermission(Page $page, ?string $controllerClass): ?Permission
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

    private function isJsBrowserContentRequest(Request $request): bool
    {
        $value = $request->headers()->get('X-AstrX-JS-Browser');
        if ($value === null || trim($value) === '') {
            return false;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return false;
        }

        $acceptRaw = $request->headers()->get('Accept', '');
        $accept = is_string($acceptRaw) ? strtolower($acceptRaw) : '';
        return $accept === '' || str_contains($accept, 'text/html') || str_contains($accept, '*/*');
    }

    private function emitServerTiming(string $name, float $started): void
    {
        // Off by default: Server-Timing / X-AstrX-Elapsed-Ms expose high-resolution
        // compute time (a timing side channel) and fingerprint the stack — both
        // undesirable on a privacy/Tor-first deployment. Enable for debugging via
        // ContentManager.expose_server_timing.
        if ($this->config->getConfig('ContentManager', 'expose_server_timing', false) !== true) {
            return;
        }
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name) ?: 'astrx';
        $dur = max(0.0, (microtime(true) - $started) * 1000.0);
        header('Server-Timing: ' . $safe . ';dur=' . number_format($dur, 2, '.', ''), false);
        header('X-AstrX-Elapsed-Ms: ' . number_format($dur, 2, '.', ''));
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
        $pdoResult = $this->injector->getClass(\PDO::class, false);
        $pdo = $pdoResult->isOk() ? $pdoResult->unwrap() : null;
        return new SiteFlags($pdo instanceof \PDO ? $pdo : null);
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

    private function resolvePage(PageHandler $pageHandler, string $pageToken): Page
    {
        $map = [];
        foreach ($pageHandler->getInternationalizedPageIds() as $row) {
            $urlId    = (string) $row['url_id'];
            $pid      = (int) $row['id'];
            $resolved = $this->translator->t($urlId);
            $map[$resolved] = $pid;
        }

        $page = null;

        if (isset($map[$pageToken])) {
            $page = $pageHandler->getPage($map[$pageToken]);
        }

        if ($page === null) {
            $id   = $pageHandler->getPageIdFromUrlId($pageToken);
            $page = $id !== null ? $pageHandler->getPage($id) : null;
        }

        // Sub-path routing: /en/user/login → pageToken='user', tail[0]='login'
        // If the primary page resolved successfully and there is a tail segment,
        // try to find a direct child page matching that slug. This lets /en/user/login
        // work as an alias for /en/login without any extra DB rows or config.
        if ($page !== null && !$page->hidden) {
            $current  = $this->injector->getClass(CurrentUrl::class);
            if ($current->isOk()) {
                /** @var CurrentUrl $currentUrl */
                $currentUrl = $current->unwrap();
                $tailSlug   = $currentUrl->tailSegment(0);
                if ($tailSlug !== null && $tailSlug !== '') {
                    // Resolve tail slug → candidate page, then confirm it is a
                    // direct child of the current page so /en/user/main cannot
                    // accidentally route to the unrelated main page.
                    $childPage = null;
                    $translatedTail = $map[$tailSlug] ?? null;
                    if ($translatedTail !== null) {
                        // Slug matched an i18n page — verify it is a child.
                        $candidate = $pageHandler->getPage($translatedTail);
                        if ($candidate !== null && !$candidate->hidden) {
                            $ancestorIds = array_column($candidate->ancestors, 'id');
                            if (in_array($page->id, $ancestorIds, true)) {
                                $childPage = $candidate;
                            }
                        }
                    }
                    if ($childPage === null) {
                        // Fallback: raw url_id match restricted to children by SQL.
                        $childPage = $pageHandler->getChildPageBySlug($page->id, $tailSlug);
                    }
                    if ($childPage !== null && !$childPage->hidden) {
                        $page = $childPage;
                        // Consume the tail segment so controllers don't see it.
                        $currentUrl->setTail(array_slice($currentUrl->tail(), 1));
                    }
                }
            }
        }

        if ($page === null) {
            http_response_code(HttpStatus::NOT_FOUND->value);

            // Default is 'error' — the url_id of the error page in the database.
            // Override via ContentManager.config.php: ['error_page_url_id' => 'my_error']
            $errorUrlId = $this->config->getConfig(
                'ContentManager',
                'error_page_url_id',
                'WORDING_ERROR',
            );
            assert(is_string($errorUrlId));

            $eid  = $pageHandler->getPageIdFromUrlId($errorUrlId);
            $page = $eid !== null ? $pageHandler->getPage($eid) : null;

            if ($page === null) {
                $page = $pageHandler->getFallbackErrorPage($errorUrlId);
            }
        }

        return $page;
    }

    // =========================================================================
    // Session ID regeneration
    // =========================================================================

    /**
     * Regenerate the session ID if a privilege-change flag was set this request
     * (login, logout, admin role change) OR the time-based rotation interval
     * for the current user's group has elapsed.
     *
     * After regeneration the old row is kept alive via the replaced_by handover
     * pointer so slow/in-flight requests using the old ID still succeed.
     */
}
