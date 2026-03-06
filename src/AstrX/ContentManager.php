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
use AstrX\Session\PrgHandler;
use PDO;
use AstrX\Session\SecureSessionHandler;

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
        // todo check that the array contains only strings. Maybe we could
        // enforce this with a custom type LanguageCode::EN etc.
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
        $request = $this->injector->getClass(Request::class)->unwrap();
        assert($request instanceof Request);


        $current = new CurrentUrl();

        $sid = null;
        if ($urlRewrite) {
            $requestUri = ($_SERVER['REQUEST_URI'] ?? '/');
            $stack = UrlStack::fromRequest($requestUri, $basePath);

            $val = $stack->pop();
            if ($val !== null && in_array($val, $availableLocales, true)) {
                $locale = $val;
                $val = $stack->pop();
                $current->set($localeKey, $val);
            } else {
                $locale = $defaultLocale;
            }

            if(!$sessionUseCookies && preg_match($sessionIdRegex, $val) === 1) {
                $sid = $val;
                $current->set($sessionKey, $val);
                $val = $stack->pop();
            }

            // page token
            $pageToken = $val;

            // i am not sure about this line... architecturally speaking.
            $request->configureRewrite(true, $current);
        } else {
            // classic mode
            $locale = $request->get($localeKey);
            $pageToken = $request->get($pageKey);

            if ($locale !== null) {
                $current->set($localeKey, $locale);
                if(!in_array($locale, $availableLocales, true)) {
                    $locale = $defaultLocale;
                }
            }

            if(!$sessionUseCookies) {
                $sid = $request->get($sessionKey);
                if ($sid !== null) {
                    $current->set($sessionKey, $sid);
                }
            }
        }
        $pageToken = $pageToken ?? $defaultPageToken;
        $current->set($pageKey, $pageToken);

        // here we have $locale, $sid and $pageToken.
        $this->translator->setLocale($locale);
        $this->moduleLoader->setLocale($locale);



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



        $sessionHandler = $this->injector
            ->createClass(SecureSessionHandler::class)
            ->drainTo($this->collector)
            ->unwrap();
        assert($sessionHandler instanceof SecureSessionHandler);

        session_set_save_handler($sessionHandler, true);

        if(!$sessionUseCookies) {
            if ($sid !== null) {
                if ($sessionHandler->validateId($sid)) {
                    session_id($sid);
                } else {
                    // Ban? LMAO or maybe log diagnostic?
                    assert(true);
                }
            }
        }

        if(session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sid = session_id();


        $prgHandler = $this->injector->getClass(PrgHandler::class)->unwrap();
        assert($prgHandler instanceof PrgHandler);

        // this is experimental ... i am still working on it.
        /*
         * my main doubts are about:
         * - serializing $_POST to $data and then using $data just for token
         * computation and then calling prgh->store on $_POST. store is very
         * likely going to serialize the data again
         * - geturl: i didn't implement it.
         * - is it okay to check isset $_POST["prg_id"] ?
         * given that when i create the post form I add an hidden field named
         *  prg_id so i can coordinate the request with the origin and
         * redirection urls. these may be redundant information but it's
         * better to be safe than sorry
         *
         * and by the way: we need to extend Request to account for the data
         * retrieved through a post-redirect-get request!
         * ( in the old codebase i used the same trick of the router... i was
         *  writing retrieved data to $_POST[] = ... but since this is bad..
         * we need to change it once more )
         *
         *
         */
        if($_POST !== array() && isset($_POST["prg_id"])) {
            $data = serialize($_POST);
            $token = hash_hmac("SHA256", $data, $sid);
            $prgHandler->store($token, $_POST);
            $redirect_url = $prgHandler->getUrl($_POST["prg_id"]);

            $response->setStatusCode(Response::HTTP_FOUND);
            $response->addHeader("Location: " . $redirect_url);
            $response->send();
            die();
        }




        $pageHandler = $this->injector->createClass(PageHandler::class)->drainTo($this->collector)->unwrap();
        assert($pageHandler instanceof PageHandler);


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

        echo "topkek";
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
        /** @var PrgHandler $prg */
        $prg = $this->injector->getClass(PrgHandler::class)->unwrap();
        assert($prg instanceof PrgHandler);

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
        // 1) Controller (if configured)
        if ($page->controller) {
            $controller = $this->instantiateControllerForPage($page->fileName);

            if ($controller instanceof \AstrX\Controller\Controller) {
                $r = $controller->handle()->drainTo($this->collector);
                if (!$r->isOk()) {
                    // Controller fatal -> force 500 page (or 404, your choice)
                    http_response_code(500);
                    // You can resolve an error page here if you want.
                    return;
                }
            } elseif (is_object($controller) && method_exists($controller, 'init')) {
                // Back-compat legacy controller (temporary)
                $controller->init();
            }
        }

        // 2) Template rendering (if configured)
        if ($page->template) {
            /** @var \AstrX\Template\TemplateEngine $engine */
            $engine = $this->injector
                ->createClass(\AstrX\Template\TemplateEngine::class)
                ->drainTo($this->collector)
                ->unwrap();
            assert($engine instanceof \AstrX\Template\TemplateEngine);

            $templateName = $page->templateFileName !== ''
                ? $page->templateFileName
                : (string)$this->config->getConfig('ContentManager', 'default_template', 'test');

            $tpl = $engine->loadTemplate($templateName);
            if ($tpl === null || !method_exists($tpl, 'render')) {
                http_response_code(500);
                return;
            }

            echo $tpl->render($this->template_args);
            return;
        }

        // 3) No template: controller must have handled output (API/avatar/etc)
        // If it didn’t, return 204
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