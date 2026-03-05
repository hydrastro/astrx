<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Result\DiagnosticsCollector;
use AstrX\I18n\Translator;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\Request;
use AstrX\Routing\UrlStack;
use AstrX\Page\Page;
use AstrX\Page\PageHandler;
use AstrX\Session\PostRedirectGet;
use PDO;

final class ContentManager
{
    /** @var array<string,mixed> */
    public array $template_args = [];

    public function __construct(
        private Injector $injector,
        private Config $config,
        private DiagnosticsCollector $collector,
        private ModuleLoader $moduleLoader,
        private Translator $translator,
    ) {}

    public function init(): void
    {
        // -------- Load configs (explicitly for modules not created by Injector) --------
        $this->config->loadModuleConfig('Routing');
        $this->config->loadModuleConfig('Session');
        $this->config->loadModuleConfig('ContentManager');
        $this->config->loadModuleConfig('PDO');

        // -------- Routing config --------
        $urlRewrite = $this->config->getConfig('Routing', 'url_rewrite', true);
        assert(is_bool($urlRewrite));
        $basePath = $this->config->getConfig('Routing', 'base_path', '/');
        assert(is_string($basePath));
        $entryPoint = $this->config->getConfig('Routing', 'entry_point', 'index.php');
        assert(is_string($entryPoint));

        $localeKey  = $this->config->getConfig('Routing', 'locale_key', 'lang');
        assert(is_string($localeKey));
        $sessionKey = $this->config->getConfig('Routing', 'session_key', 'sid');
        assert(is_string($sessionKey));
        $pageKey    = $this->config->getConfig('Routing', 'page_key', 'page');
        assert(is_string($pageKey));
        $defaultPageToken = $this->config->getConfig('Routing', 'default_page', 'main');
        assert(is_string($defaultPageToken));

        // -------- Locale config --------
        $availableLocales = $this->config->getConfig('Prelude', 'available_languages', ['en']);
        assert(is_array($availableLocales));
        $defaultLocale = $this->config->getConfig('Prelude', 'default_language', 'en');
        assert(is_string($defaultLocale));

        // -------- Session config --------
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

        // -------- Request + canonical bag --------
        /** @var Request $request */
        $request = $this->injector->getClass(Request::class)->unwrap();
        assert($request instanceof Request);

        $current = new CurrentUrl();

        // -------- Parse head (rewrite/query) into canonical keys --------
        if ($urlRewrite) {
            $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $stack = UrlStack::fromRequest($requestUri, $basePath);

            // [/lang]? [/sid]? /page ...
            $first = $stack->pop();
            $locale = $defaultLocale;

            $pageCandidate = null;
            if ($first !== null && in_array($first, $availableLocales, true)) {
                $locale = $first;
            } else {
                $pageCandidate = $first; // could be sid or page
            }

            $current->set($localeKey, $locale);

            $sid = null;
            $next = $stack->pop();

            if (!$sessionUseCookies && $next !== null && preg_match($sessionIdRegex, $next) === 1) {
                $sid = $next;
                $next = $stack->pop();
            } elseif (!$sessionUseCookies && $pageCandidate !== null && preg_match($sessionIdRegex, $pageCandidate) === 1) {
                // first segment was actually sid (locale missing)
                $sid = $pageCandidate;
                $pageCandidate = null;
            }

            if ($sid !== null) {
                $current->set($sessionKey, $sid);
            }

            $pageToken = $next ?? $pageCandidate ?? $defaultPageToken;
            if ($pageToken === '') $pageToken = $defaultPageToken;
            $current->set($pageKey, $pageToken);

            $request->configureRewrite(true, $current);
            // Keep $stack around for subcontroller consumption later:
            // For now we keep it local and pass remaining segments to controller if you want.
            $remainingSegments = $stack->remaining();
        } else {
            $locale = $request->get($localeKey, $defaultLocale);
            assert(is_string($locale));
            if (!in_array($locale, $availableLocales, true)) {
                $locale = $defaultLocale;
            }
            $current->set($localeKey, $locale);

            if (!$sessionUseCookies) {
                $sid = $request->get($sessionKey, '');
                if (is_string($sid) && preg_match($sessionIdRegex, $sid) === 1) {
                    $current->set($sessionKey, $sid);
                }
            }

            $pageToken = $request->get($pageKey, $defaultPageToken);
            assert(is_string($pageToken));
            if ($pageToken === '') $pageToken = $defaultPageToken;
            $current->set($pageKey, $pageToken);

            $request->configureRewrite(false, null);
            $remainingSegments = []; // query mode: “rest” comes from other canonical keys
        }

        // Locale is known now
        $locale = (string)$current->get($localeKey, $defaultLocale);
        $this->translator->setLocale($locale);
        $this->moduleLoader->setLocale($locale);

        // -------- Setup PDO (manual object, so module config must be loaded explicitly) --------
        $this->setupPdo();

        /** @var PageHandler $pageHandler */
        $pageHandler = $this->injector->createClass(PageHandler::class)->drainTo($this->collector)->unwrap();
        assert($pageHandler instanceof PageHandler);

        // -------- Session start + PRG hook (as “page” prefix) --------
        $this->setupSession(
            current: $current,
            sessionUseCookies: $sessionUseCookies,
            sessionKey: $sessionKey,
            sessionIdRegex: $sessionIdRegex
        );

        // PRG endpoint design: if pageToken === "prg", treat next segment as token and next as real page
        $pageToken = (string)$current->get($pageKey, $defaultPageToken);
        if ($pageToken === 'prg') {
            $this->handlePrgAsPage(
                urlRewrite: $urlRewrite,
                request: $request,
                current: $current,
                prgTokenKey: $prgTokenKey,
                prgTokenRegex: $prgTokenRegex,
                defaultPageToken: $defaultPageToken,
                remainingSegments: $remainingSegments
            );

            // after PRG rewrite, update page token
            $pageToken = (string)$current->get($pageKey, $defaultPageToken);
        }

        // -------- Resolve Page from DB (supports i18n url tokens) --------
        $page = $this->resolvePage($pageHandler, $pageToken, $locale);
        assert($page instanceof Page);

        // -------- Dispatch controller or render template --------
        $this->dispatchPage($page, $remainingSegments);
    }

    private function setupPdo(): void
    {
        $dsn = $this->config->getConfig("PDO", "db_type", "mysql");
        assert(is_string($dsn));
        $host = $this->config->getConfig("PDO", "db_host", "mysql");
        assert(is_string($host));
        $dbname = $this->config->getConfig("PDO", "db_name", "content_manager");
        assert(is_string($dbname));
        $username = $this->config->getConfig("PDO", "db_username", "user");
        assert(is_string($username));
        $passwd = $this->config->getConfig("PDO", "db_password", "password");
        assert(is_string($passwd));

        $pdo = new PDO(
            $dsn . ":host=" . $host . ";dbname=" . $dbname . ";",
            $username,
            $passwd
        );

        $emulate = $this->config->getConfig('PDO', 'emulate_prepares', false);
        assert(is_bool($emulate));
        $errExc = $this->config->getConfig('PDO', 'errmode_exception', true);
        assert(is_bool($errExc));
        $fetchAssoc = $this->config->getConfig('PDO', 'default_fetch_assoc', true);
        assert(is_bool($fetchAssoc));

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $errExc ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $fetchAssoc ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH);

        $this->injector->setClass($pdo);
    }

    private function setupSession(
        CurrentUrl $current,
        bool $sessionUseCookies,
        string $sessionKey,
        string $sessionIdRegex
    ): void {
        // If you have a DB-backed SecureSessionHandler, install it here.
        // Otherwise, default PHP session handler is fine.

        if (!$sessionUseCookies) {
            $sid = $current->get($sessionKey, '');
            if (is_string($sid) && $sid !== '' && preg_match($sessionIdRegex, $sid) === 1) {
                session_id($sid);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // ensure canonical sid is set (even if cookies are used)
        $current->set($sessionKey, (string)session_id());
    }

    /**
     * PRG as a routing page:
     *  - rewrite: /.../prg/<token>/<realPage>/...
     *  - query:   ?page=prg&prg=<token>&next=<realPage>
     *
     * After successful PRG load, merges stored POST into $_POST and rewrites pageKey to realPage.
     *
     * @param list<string> $remainingSegments (rewrite only)
     */
    private function handlePrgAsPage(
        bool $urlRewrite,
        Request $request,
        CurrentUrl $current,
        string $prgTokenKey,
        string $prgTokenRegex,
        string $defaultPageToken,
        array $remainingSegments
    ): void {
        /** @var PostRedirectGet $prg */
        $prg = $this->injector->getClass(PostRedirectGet::class)->unwrap();
        assert($prg instanceof PostRedirectGet);

        if ($urlRewrite) {
            // Remaining segments begin right after 'prg' was consumed into pageKey.
            // We need token + realPage as first two remaining segments.
            $token = $remainingSegments[0] ?? null;
            $realPage = $remainingSegments[1] ?? null;

            if (!is_string($token) || preg_match($prgTokenRegex, $token) !== 1) {
                // invalid token -> ignore PRG and fall back to default page
                $current->set('page', $defaultPageToken);
                return;
            }
            if (!is_string($realPage) || $realPage === '') {
                $realPage = $defaultPageToken;
            }

            $data = $prg->load($token);
            if ($data !== []) {
                $_POST = array_merge($_POST, $data);
            }
            $prg->clear($token);

            $current->set('page', $realPage);
            return;
        }

        // query mode
        $token = $request->get($prgTokenKey, '');
        if (!is_string($token) || preg_match($prgTokenRegex, $token) !== 1) {
            $current->set('page', $defaultPageToken);
            return;
        }

        $realPage = $request->get('next', $defaultPageToken);
        if (!is_string($realPage) || $realPage === '') {
            $realPage = $defaultPageToken;
        }

        $data = $prg->load($token);
        if ($data !== []) {
            $_POST = array_merge($_POST, $data);
        }
        $prg->clear($token);

        $current->set('page', $realPage);
    }

    private function resolvePage(PageHandler $pageHandler, string $pageToken, string $locale): Page
    {
        // 1) direct match (non-i18n url_id)
        $id = $pageHandler->getPageIdFromUrlId($pageToken);
        $page = $id !== null ? $pageHandler->getPage($id) : null;

        // 2) i18n match via translator
        if ($page === null) {
            $map = [];
            foreach ($pageHandler->getInternationalizedPageIds() as $row) {
                $urlId = (string)$row['url_id']; // e.g. "WORDING_MAIN"
                $pid = (int)$row['id'];
                $resolved = $this->translator->t($urlId);
                $map[$resolved] = $pid;
            }

            if (isset($map[$pageToken])) {
                $page = $pageHandler->getPage($map[$pageToken]);
            }
        }

        if ($page === null || $page->hidden) {
            http_response_code(404);

            $errorUrlId = $this->config->getConfig('ContentManager', 'error_page_url_id', 'WORDING_ERROR');
            assert(is_string($errorUrlId));

            $eid = $pageHandler->getPageIdFromUrlId($errorUrlId);
            $page = $eid !== null ? $pageHandler->getPage($eid) : null;

            if ($page === null) {
                $page = $pageHandler->getFallbackErrorPage($errorUrlId);
            }
        }

        return $page;
    }

    /** @param list<string> $remainingSegments */
    private function dispatchPage(Page $page, array $remainingSegments): void
    {
        // If controller exists, call it. Otherwise, render template (if enabled).
        if ($page->controller) {
            $controller = $this->instantiateControllerForPage($page->fileName);
            if (is_object($controller) && method_exists($controller, 'init')) {
                // You can inject $remainingSegments by setter/interface later.
                $controller->init();
                return;
            }
        }

        if ($page->template) {
            /** @var \AstrX\Template\TemplateEngine $engine */
            $engine = $this->injector->createClass(\AstrX\Template\TemplateEngine::class)->drainTo($this->collector)->unwrap();
            assert($engine instanceof \AstrX\Template\TemplateEngine);

            $templateName = $page->templateFileName !== '' ? $page->templateFileName : (string)$this->config->getConfig('ContentManager', 'default_template', 'test');
            assert(is_string($templateName));

            $tpl = $engine->loadTemplate($templateName);
            if ($tpl !== null && method_exists($tpl, 'render')) {
                echo $tpl->render($this->template_args);
                return;
            }
        }

        // fall back: nothing to do
        http_response_code(http_response_code() ?: 204);
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