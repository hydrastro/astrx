<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\UrlMode;
use AstrX\Routing\RoutingConfig;
use AstrX\Routing\RoutingContext;
use AstrX\Routing\RoutingAliasLoader;
use AstrX\Routing\Router;
use AstrX\Routing\ParsedRoute;
use AstrX\Routing\RouteState;
use AstrX\Routing\RouteStack;
use AstrX\Routing\RewriteRouteInput;
use AstrX\Routing\QueryRouteInput;
use AstrX\Routing\RouteInput;
use AstrX\Routing\Dispatcher;
use AstrX\Routing\EntryController;
use AstrX\Template\TemplateEngine;
use PDO;

final class ContentManager
{
    public array $template_args = [];

    public function __construct(
        private Injector $injector,
        private Config $config,
        private DiagnosticsCollector $collector,
        private ModuleLoader $moduleLoader,
        private \AstrX\I18n\Translator $translator,
    ) {}

    public function init(): void
    {
        // -----------------------
        // A) Build routing mechanics (RoutingConfig)
        // -----------------------
        $modeRaw = (string)$this->config->getConfig('Routing', 'mode', UrlMode::REWRITE->value);
        $mode = $modeRaw === UrlMode::QUERY->value ? UrlMode::QUERY : UrlMode::REWRITE;

        $basePath = (string)$this->config->getConfig('Routing', 'base_path', '/');
        $entryPoint = (string)$this->config->getConfig('Routing', 'entry_point', 'index.php');

        $routingCfg = new RoutingConfig($mode, $basePath, $entryPoint);

        // -----------------------
        // B) Policy owned here (RoutingContext)
        // -----------------------
        $availableLocales = $this->config->getConfig('Prelude', 'available_languages', ['en']);
        if (!is_array($availableLocales)) $availableLocales = ['en'];
        $availableLocales = array_values(array_filter($availableLocales, fn($x) => is_string($x) && $x !== ''));

        $defaultLocale = (string)$this->config->getConfig('Prelude', 'default_language', $availableLocales[0] ?? 'en');

        $sessionUseCookies = (bool)$this->config->getConfig('Session', 'use_cookies', true);
        $sessionIdRegex = (string)$this->config->getConfig('Session', 'session_id_regex', '/^[\da-fA-F]{64,256}$/');

        $localeKey  = (string)$this->config->getConfig('Routing', 'locale_key', 'lang');
        $sessionKey = (string)$this->config->getConfig('Routing', 'session_key', 'sid');
        $pageKey    = (string)$this->config->getConfig('Routing', 'page_key', 'page');

        $defaultPage = (string)$this->config->getConfig('Routing', 'default_page', 'main');

        $ctx = new RoutingContext(
            defaultLocale: $defaultLocale,
            availableLocales: $availableLocales,
            sessionUseCookies: $sessionUseCookies,
            sessionIdRegex: $sessionIdRegex,
            localeKey: $localeKey,
            sessionKey: $sessionKey,
            pageKey: $pageKey
        );

        // -----------------------
        // C) Read raw request
        // -----------------------
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';

        /** @var array<string,string> $query */
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) $query[$k] = (string)$v;
        }

        // -----------------------
        // D) Head parsing (locale/sid/page) using alias files for QUERY mode
        // -----------------------
        $aliasLoader = new RoutingAliasLoader(RESOURCE_LANG_DIR);

        $state = new RouteState();

        $input = $this->makeRouteInputAndParseHead(
            cfg: $routingCfg,
            ctx: $ctx,
            aliasLoader: $aliasLoader,
            query: $query,
            requestUri: (string)$requestUri,
            defaultPage: $defaultPage,
            outState: $state
        );

        // locale is now known -> enable module lang loading
        $locale = $state->get($ctx->localeKey, $ctx->defaultLocale) ?? $ctx->defaultLocale;
        $this->translator->setLocale($locale);
        $this->moduleLoader->setLocale($locale);

        // -----------------------
        // E) Setup PDO + session early (needed for PageHandler + PRG)
        // -----------------------
        $this->setupPdo();
        $this->setupSession($state, $ctx);

        // -----------------------
        // F) Resolve Page from DB
        // -----------------------
        /** @var \PageHandler $pageHandler */
        $pageHandler = $this->injector->createClass(\PageHandler::class)->drainTo($this->collector)->unwrap();

        $pageToken = $state->get($ctx->pageKey, $defaultPage) ?? $defaultPage;

        $page = $this->resolvePage($pageHandler, $pageToken, $locale);

        // Put page info into template args (base)
        $this->template_args = [
            'locale' => $locale,
            'page' => $pageToken,
        ];

        // -----------------------
        // G) Instantiate controller and run controller chain
        // -----------------------
        $dispatcher = new Dispatcher();

        $entry = new EntryController($ctx, function(string $pageId) use ($page) {
            // pageId can be the token; we’re using DB page->file_name for controller mapping
            if (!$page->controller) return null;
            return $this->instantiateControllerForPage($page->file_name);
        });

        $result = $dispatcher->dispatch($entry, $state, $input);
        $result->drainTo($this->collector);

        // -----------------------
        // H) Render template
        // -----------------------
        $templateFile = $page->template_file_name !== ''
            ? $page->template_file_name
            : (string)$this->config->getConfig('ContentManager', 'default_template', 'test');

        /** @var TemplateEngine $templateEngine */
        $templateEngine = $this->injector->createClass(TemplateEngine::class)->drainTo($this->collector)->unwrap();
        if (method_exists($templateEngine, 'setDiagnosticSink')) {
            $templateEngine->setDiagnosticSink($this->collector);
        }

        $r = $templateEngine->renderTemplate($templateFile, $this->template_args);
        $r->drainTo($this->collector);

        echo $r->valueOr('<h1>Template failed</h1>');
    }

    private function makeRouteInputAndParseHead(
        RoutingConfig $cfg,
        RoutingContext $ctx,
        RoutingAliasLoader $aliasLoader,
        array $query,
        string $requestUri,
        string $defaultPage,
        RouteState $outState
    ): RouteInput {
        if ($cfg->mode === UrlMode::REWRITE) {
            $stack = RouteStack::fromRequestUri($requestUri, $cfg->basePath);

            // optional locale
            $locale = $ctx->defaultLocale;
            $p = $stack->peek();
            if ($p !== null && $ctx->isLocale($p)) {
                $locale = (string)$stack->take();
            }
            $outState->set($ctx->localeKey, $locale);

            // optional sid (only if cookies disabled)
            $sid = null;
            if (!$ctx->sessionUseCookies) {
                $p = $stack->peek();
                if ($p !== null && $ctx->isSessionId($p)) {
                    $sid = (string)$stack->take();
                }
            }
            $outState->set($ctx->sessionKey, $sid);

            // page (first required, default if absent)
            $page = $stack->take();
            $outState->set($ctx->pageKey, $page ?? $defaultPage);

            return new RewriteRouteInput($stack);
        }

        // QUERY MODE: determine locale by trying each locale's global routing aliases
        $locale = $ctx->defaultLocale;

        foreach ($ctx->availableLocales as $candidate) {
            $globalAliases = $aliasLoader->loadGlobal($candidate);
            $extLangKey = $globalAliases[$ctx->localeKey] ?? $ctx->localeKey;

            if (isset($query[$extLangKey]) && $ctx->isLocale($query[$extLangKey])) {
                $locale = $query[$extLangKey];
                break;
            }
        }

        $outState->set($ctx->localeKey, $locale);

        // Build query input with GLOBAL aliases for that locale (entry controller owns these)
        $global = $aliasLoader->loadGlobal($locale);
        $qin = new QueryRouteInput($query, $global);

        // sid only if cookies disabled
        if (!$ctx->sessionUseCookies) {
            $outState->set($ctx->sessionKey, $qin->queryValue($ctx->sessionKey));
        } else {
            $outState->set($ctx->sessionKey, null);
        }

        // page with default
        $outState->set($ctx->pageKey, $qin->queryValue($ctx->pageKey) ?? $defaultPage);

        return $qin;
    }

    private function setupPdo(): void
    {
        $dsn = (string)$this->config->getConfig("PDO", "db_type", "mysql");
        $host = (string)$this->config->getConfig("PDO", "db_host", "mysql");
        $dbname = (string)$this->config->getConfig("PDO", "db_name", "content_manager");
        $username = (string)$this->config->getConfig("PDO", "db_username", "user");
        $passwd = (string)$this->config->getConfig("PDO", "db_password", "password");

        $pdo = new PDO(
            $dsn . ":host=" . $host . ";dbname=" . $dbname . ";",
            $username,
            $passwd
        );

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, (bool)$this->config->getConfig('PDO', 'emulate_prepares', false));
        $pdo->setAttribute(PDO::ATTR_ERRMODE, (bool)$this->config->getConfig('PDO', 'errmode_exception', true) ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, (bool)$this->config->getConfig('PDO', 'default_fetch_assoc', true) ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH);

        $this->injector->setClass($pdo);
    }

    private function setupSession(RouteState $state, RoutingContext $ctx): void
    {
        /** @var \SecureSessionHandler $handler */
        $handler = $this->injector->getClass(\SecureSessionHandler::class)->unwrap();
        session_set_save_handler($handler, true);

        $sid = $state->get($ctx->sessionKey);

        if (!$ctx->sessionUseCookies && is_string($sid) && $sid !== '' && $ctx->isSessionId($sid)) {
            if ($handler->validateId($sid)) {
                session_id($sid);
            } else {
                // fixation attempt policy: emit diagnostic or ban later
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $state->set($ctx->sessionKey, session_id());
    }

    private function resolvePage(\PageHandler $pageHandler, string $pageToken, string $locale): \Page
    {
        // 1) try direct url_id match (non-i18n)
        $id = $pageHandler->getPageIdFromUrlId($pageToken);
        $page = $id !== null ? $pageHandler->getPage($id) : null;

        // 2) fallback: i18n pages mapping by translating their url_id keys
        if ($page === null) {
            $map = [];
            foreach ($pageHandler->getInternationalizedPageIds() as $row) {
                $urlId = (string)$row['url_id']; // e.g. "WORDING_MAIN"
                $pid = (int)$row['id'];

                // Translation key convention: use url_id as key in Translator catalogs
                $resolved = $this->translator->t($urlId);
                $map[$resolved] = $pid;
            }

            if (isset($map[$pageToken])) {
                $page = $pageHandler->getPage($map[$pageToken]);
            }
        }

        if ($page === null || $page->hidden) {
            http_response_code(404);

            $errorUrlId = (string)$this->config->getConfig('ContentManager', 'error_page_url_id', 'WORDING_ERROR');
            $eid = $pageHandler->getPageIdFromUrlId($errorUrlId);

            $page = $eid !== null ? $pageHandler->getPage($eid) : null;
            if ($page === null) {
                $page = $pageHandler->getErrorPage();
            }
        }

        return $page;
    }

    private function instantiateControllerForPage(string $fileName): ?object
    {
        $class = str_replace('_', '', ucwords($fileName, '_')) . 'Controller';
        if (!class_exists($class)) {
            return null;
        }

        $r = $this->injector->createClass($class)->drainTo($this->collector);
        if (!$r->isOk()) return null;

        return $r->unwrap();
    }
}