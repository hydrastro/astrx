<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Controller\Controller;
use AstrX\Http\HttpStatus;
use AstrX\Http\Request;
use AstrX\Http\Response;
use AstrX\I18n\Locale;
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Navbar\NavbarHandler;
use AstrX\Page\Page;
use AstrX\Page\PageHandler;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlStack;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;
use AstrX\Session\PrgHandler;
use AstrX\Session\SecureSessionHandler;
use AstrX\Template\DefaultTemplateContext;
use AstrX\Template\TemplateEngine;
use PDO;

final class ContentManager
{
    public const string ID_INVALID_PRG_ID = 'astrx.session/invalid_prg_id';
    public const DiagnosticLevel LVL_INVALID_PRG_ID = DiagnosticLevel::WARNING;

    public function __construct(
        private readonly Injector $injector,
        private readonly Config $config,
        private readonly DiagnosticsCollector $collector,
        private readonly ModuleLoader $moduleLoader,
        private readonly Translator $translator,
    ) {}

    public function init(): void
    {
        $this->config->loadModuleConfig('Routing');
        $this->config->loadModuleConfig('Session');
        $this->config->loadModuleConfig('ContentManager');
        $this->config->loadModuleConfig('PDO');

        $urlRewrite = $this->config->getConfig('Routing', 'url_rewrite', true);
        assert(is_bool($urlRewrite));

        $basePath = $this->config->getConfig('Routing', 'base_path', '/');
        assert(is_string($basePath));

        $localeKey = $this->config->getConfig('Routing', 'locale_key', 'lang');
        assert(is_string($localeKey));

        $sessionKey = $this->config->getConfig('Routing', 'session_key', 'sid');
        assert(is_string($sessionKey));

        $pageKey = $this->config->getConfig('Routing', 'page_key', 'page');
        assert(is_string($pageKey));

        $defaultPageToken = $this->config->getConfig('Routing', 'default_page', 'WORDING_MAIN');
        assert(is_string($defaultPageToken));

        $availableLocales = $this->config->getConfig('Prelude', 'available_languages', ['en']);
        assert(is_array($availableLocales));

        $defaultLocaleStr = $this->config->getConfig('Prelude', 'default_language', 'en');
        assert(is_string($defaultLocaleStr));

        $defaultLocale = Locale::fromStringOrDefault($defaultLocaleStr, Locale::EN);
        if (!$defaultLocale->isAllowed($availableLocales)) {
            $defaultLocale = Locale::EN;
        }

        $sessionUseCookies = $this->config->getConfig('Session', 'use_cookies', true);
        assert(is_bool($sessionUseCookies));

        $sessionIdRegex = $this->config->getConfig('Session', 'session_id_regex', '/^[\da-fA-F]{256}$/');
        assert(is_string($sessionIdRegex));
        assert(@preg_match($sessionIdRegex, '') !== false);

        $prgTokenKey = $this->config->getConfig('Session', 'prg_token_key', 'prg');
        assert(is_string($prgTokenKey));

        $prgTokenRegex = $this->config->getConfig('Session', 'prg_token_regex', '/^[\da-fA-F]{64}$/');
        assert(is_string($prgTokenRegex));
        assert(@preg_match($prgTokenRegex, '') !== false);

        $requestResult = Request::fromGlobals()->drainTo($this->collector);
        $request = $requestResult->unwrap(); // always ok — fromGlobals never returns err
        $this->injector->setClass($request);

        $current = new CurrentUrl();

        [$locale, $sidCandidate, $pageToken] = $this->parseRoutingHead(
            urlRewrite:        $urlRewrite,
            request:           $request,
            basePath:          $basePath,
            availableLocales:  $availableLocales,
            defaultLocale:     $defaultLocale,
            sessionUseCookies: $sessionUseCookies,
            sessionIdRegex:    $sessionIdRegex,
            localeKey:         $localeKey,
            sessionKey:        $sessionKey,
            pageKey:           $pageKey,
            defaultPageToken:  $defaultPageToken,
            current:           $current,
        );

        // Register the now-populated CurrentUrl so that injectable services
        // (e.g. NavbarHandler) can receive the current locale, session id, etc.
        $this->injector->setClass($current);

        $this->translator->setLocale($locale->value);
        $this->moduleLoader->setLocale($locale->value);

        $pagesDomain = $this->config->getConfig('ContentManager', 'pages_lang_domain', 'pages');
        assert(is_string($pagesDomain));
        $this->translator->loadDomain(defined('LANG_DIR') ? LANG_DIR : '', $pagesDomain);

        // Navbar display labels — loaded globally so NavbarHandler can resolve
        // WORDING_ entry names regardless of which page is being rendered.
        $navbarDomain = $this->config->getConfig('ContentManager', 'navbar_lang_domain', 'Navbar');
        assert(is_string($navbarDomain));
        $this->translator->loadDomain(defined('LANG_DIR') ? LANG_DIR : '', $navbarDomain);

        // Diagnostic messages — loaded into DiagnosticRenderer's own catalog,
        // NOT into the Translator, to prevent the recursion where rendering a
        // MissingTranslationDiagnostic would emit another MissingTranslationDiagnostic.
        $diagnosticsDomain = $this->config->getConfig('ContentManager', 'diagnostics_lang_domain', 'Diagnostics');
        assert(is_string($diagnosticsDomain));
        $rendererResult = $this->injector->getClass(DiagnosticRenderer::class);
        if ($rendererResult->isOk()) {
            /** @var DiagnosticRenderer $renderer */
            $renderer = $rendererResult->unwrap();
            $renderer->loadDomain(defined('LANG_DIR') ? LANG_DIR : '', $diagnosticsDomain);
        }

        $this->initPDO();

        $sessionResult = $this->injector->createClass(SecureSessionHandler::class)
            ->drainTo($this->collector);

        if (!$sessionResult->isOk()) {
            http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
            return;
        }

        /** @var SecureSessionHandler $sessionHandler */
        $sessionHandler = $sessionResult->unwrap();
        assert($sessionHandler instanceof SecureSessionHandler);

        session_set_save_handler($sessionHandler, true);

        if (!$sessionUseCookies && $sidCandidate !== null) {
            if ($sessionHandler->validateId($sidCandidate)) {
                session_id($sidCandidate);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sid = (string) session_id();
        $current->set($sessionKey, $sid);
        $request->query()->set($sessionKey, $sid);

        $pageToken = ($pageToken === '' ? $defaultPageToken : $pageToken);
        $current->set($pageKey, $pageToken);
        $request->query()->set($pageKey, $pageToken);

        $prgResult = $this->injector->getClass(PrgHandler::class);
        if (!$prgResult->isOk()) {
            http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
            return;
        }

        /** @var PrgHandler $prgHandler */
        $prgHandler = $prgResult->unwrap();

        if ($request->body()->all() !== [] && $request->body()->has('prg_id')) {
            $prgIdResult = $request->body()->getString('prg_id')->drainTo($this->collector);
            $prgId = $prgIdResult->valueOr(null);

            if ($prgId === null || !$prgHandler->hasTarget($prgId)) {
                $this->collector->emit(new InvalidPrgIdDiagnostic(
                                           self::ID_INVALID_PRG_ID,
                                           self::LVL_INVALID_PRG_ID,
                                           $prgId,
                                       ));
                http_response_code(HttpStatus::BAD_REQUEST->value);
                return;
            }

            $token = $prgHandler->storeFromPayload($request->body()->all());
            $sendResult = Response::redirect($prgHandler->getUrl($prgId, $token))->send()
                ->drainTo($this->collector);
            if (!$sendResult->isOk()) {
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
                return;
            }
            exit;
        }

        $pageHandlerResult = $this->injector->createClass(PageHandler::class)
            ->drainTo($this->collector);

        if (!$pageHandlerResult->isOk()) {
            http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
            return;
        }

        /** @var PageHandler $pageHandler */
        $pageHandler = $pageHandlerResult->unwrap();

        $page = $this->resolvePage($pageHandler, $pageToken);
        $this->injector->setClass($page);

        // Load the page-specific lang file (e.g. lang/en/Main.php for fileName='main').
        // This must happen before DefaultTemplateContext::buildBase() runs so that
        // title, description, and keyword translations are already in the catalog.
        $langDir = defined('LANG_DIR') ? LANG_DIR : '';
        $this->translator->loadDomain($langDir, ucfirst($page->fileName));

        $ctxResult = $this->injector->createClass(DefaultTemplateContext::class)
            ->drainTo($this->collector);

        if (!$ctxResult->isOk()) {
            http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
            return;
        }

        /** @var DefaultTemplateContext $ctx */
        $ctx = $ctxResult->unwrap();
        $ctx->buildBase($page);

        // Populate navbar. Failure is non-fatal — the template renders with an
        // empty navbar rather than taking down the whole page.
        $navbarId = (int) $this->config->getConfig('ContentManager', 'public_navbar_id', 1);
        $navbarResult = $this->injector->createClass(NavbarHandler::class)
            ->drainTo($this->collector);
        if ($navbarResult->isOk()) {
            /** @var NavbarHandler $navbarHandler */
            $navbarHandler = $navbarResult->unwrap();
            $ctx->set('navbar', $navbarHandler->getNavbarEntries($navbarId, $page->ancestors));
        }

        if ($page->controller) {
            $short = str_replace('_', '', ucwords($page->fileName, '_')) . 'Controller';
            $fqcn  = 'AstrX\\Controller\\' . $short;

            if (class_exists($fqcn)) {
                $controllerResult = $this->injector->createClass($fqcn)
                    ->drainTo($this->collector);

                if (!$controllerResult->isOk()) {
                    http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
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
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
            }
        }

        if ($page->template) {
            $engineResult = $this->injector->createClass(TemplateEngine::class)
                ->drainTo($this->collector);

            if (!$engineResult->isOk()) {
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
                return;
            }

            /** @var TemplateEngine $engine */
            $engine = $engineResult->unwrap();

            $templateName = $page->templateFileName !== ''
                ? $page->templateFileName
                : (string) $this->config->getConfig('ContentManager', 'default_template', 'default');

            $ctx->finalise();

            $renderResult = $engine->renderTemplate($templateName, $ctx->all())
                ->drainTo($this->collector);

            if (!$renderResult->isOk()) {
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
                return;
            }

            echo $renderResult->unwrap();
            return;
        }

        $currentCode = http_response_code();
        if ($currentCode === false || $currentCode === 200) {
            http_response_code(HttpStatus::NO_CONTENT->value);
        }
    }

    /** @return array{Locale, ?string, string} */
    private function parseRoutingHead(
        bool $urlRewrite,
        Request $request,
        string $basePath,
        array $availableLocales,
        Locale $defaultLocale,
        bool $sessionUseCookies,
        string $sessionIdRegex,
        string $localeKey,
        string $sessionKey,
        string $pageKey,
        string $defaultPageToken,
        CurrentUrl $current,
    ): array {
        $sidCandidate = null;

        if ($urlRewrite) {
            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $stack      = UrlStack::fromRequest($requestUri, $basePath);

            $a = $stack->pop();
            $b = $stack->pop();

            $localeFromUrl = ($a !== null && in_array($a, $availableLocales, true));
            $locale        = $localeFromUrl
                ? Locale::fromStringOrDefault($a, $defaultLocale)
                : $defaultLocale;

            if (!$localeFromUrl) {
                $b = $a;
            }

            $current->set($localeKey, $locale->value);
            $request->query()->set($localeKey, $locale->value);

            if (
                !$sessionUseCookies
                && $b !== null
                && preg_match($sessionIdRegex, $b) === 1
            ) {
                $sidCandidate = $b;
                $current->set($sessionKey, $sidCandidate);
                $request->query()->set($sessionKey, $sidCandidate);
                $pageToken = $stack->pop() ?? $defaultPageToken;
            } else {
                $pageToken = $b ?? $defaultPageToken;
            }

            $current->set($pageKey, $pageToken);
            $request->query()->set($pageKey, $pageToken);

            // Store remaining path segments for controllers to consume as
            // page-specific sub-params (e.g. page number, sort order).
            $current->setTail($stack->remaining());

            return [$locale, $sidCandidate, $pageToken];
        }

        $rawLocale = $request->query()->get($localeKey);
        $locale    = Locale::fromStringOrDefault(
            is_string($rawLocale) ? $rawLocale : null,
            $defaultLocale,
        );
        if (!$locale->isAllowed($availableLocales)) {
            $locale = $defaultLocale;
        }

        $current->set($localeKey, $locale->value);
        $request->query()->set($localeKey, $locale->value);

        if (!$sessionUseCookies) {
            $rawSid = $request->query()->get($sessionKey);
            if (is_string($rawSid) && preg_match($sessionIdRegex, $rawSid) === 1) {
                $sidCandidate = $rawSid;
                $current->set($sessionKey, $sidCandidate);
                $request->query()->set($sessionKey, $sidCandidate);
            }
        }

        $rawPage   = $request->query()->get($pageKey);
        $pageToken = (is_string($rawPage) && $rawPage !== '') ? $rawPage : $defaultPageToken;

        $current->set($pageKey, $pageToken);
        $request->query()->set($pageKey, $pageToken);

        return [$locale, $sidCandidate, $pageToken];
    }

    private function initPDO(): void
    {
        $driver  = $this->config->getConfig('PDO', 'db_type', 'mysql');
        assert(is_string($driver));
        $host    = $this->config->getConfig('PDO', 'db_host', 'localhost');
        assert(is_string($host));
        $dbname  = $this->config->getConfig('PDO', 'db_name', 'content_manager');
        assert(is_string($dbname));
        $username = $this->config->getConfig('PDO', 'db_username', 'user');
        assert(is_string($username));
        $passwd  = $this->config->getConfig('PDO', 'db_password', 'password');
        assert(is_string($passwd));

        $dsn = $driver . ':host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $username, $passwd);

        $emulate    = $this->config->getConfig('PDO', 'emulate_prepares', false);
        assert(is_bool($emulate));
        $errExc     = $this->config->getConfig('PDO', 'errmode_exception', true);
        assert(is_bool($errExc));
        $fetchAssoc = $this->config->getConfig('PDO', 'default_fetch_assoc', true);
        assert(is_bool($fetchAssoc));

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $errExc ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $fetchAssoc ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH);

        $this->injector->setClass($pdo);
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
        if ($page === null || $page->hidden) {
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
}