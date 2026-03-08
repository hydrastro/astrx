<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Result\DiagnosticsCollector;
use AstrX\I18n\Translator;
use AstrX\Routing\CurrentUrl;
use AstrX\Http\Request;
use AstrX\Routing\UrlStack;
use AstrX\Page\Page;
use AstrX\Page\PageHandler;
use AstrX\Session\PrgHandler;
use PDO;
use AstrX\Session\SecureSessionHandler;
use AstrX\Http\Response;
use RuntimeException;
use AstrX\Controller\Controller;
use AstrX\Template\TemplateEngine;

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
    ) {
    }

    public function init()
    : void
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
        $entryPoint = $this->config->getConfig(
            'Routing',
            'entry_point',
            'index.php'
        );
        assert(is_string($entryPoint));

        $localeKey = $this->config->getConfig('Routing', 'locale_key', 'lang');
        assert(is_string($localeKey));
        $sessionKey = $this->config->getConfig('Routing', 'session_key', 'sid');
        assert(is_string($sessionKey));
        $pageKey = $this->config->getConfig('Routing', 'page_key', 'page');
        assert(is_string($pageKey));
        $defaultPageToken = $this->config->getConfig(
            'Routing',
            'default_page',
            'main'
        );
        assert(is_string($defaultPageToken));

        // -------- Locale config --------
        $availableLocales = $this->config->getConfig(
            'Prelude',
            'available_languages',
            ['en']
        );
        // todo check that the array contains only strings. Maybe we could
        // enforce this with a custom type LanguageCode::EN etc.
        assert(is_array($availableLocales));
        $defaultLocale = $this->config->getConfig(
            'Prelude',
            'default_language',
            'en'
        );
        assert(is_string($defaultLocale));

        // -------- Session config --------
        $sessionUseCookies = $this->config->getConfig(
            'Session',
            'use_cookies',
            true
        );
        assert(is_bool($sessionUseCookies));
        $sessionIdRegex = $this->config->getConfig(
            'Session',
            'session_id_regex',
            '/^[\da-fA-F]{256}$/'
        );
        assert(is_string($sessionIdRegex));
        assert(@preg_match($sessionIdRegex, '') !== false);

        $prgTokenKey = $this->config->getConfig(
            'Session',
            'prg_token_key',
            'prg'
        );
        assert(is_string($prgTokenKey));
        $prgTokenRegex = $this->config->getConfig(
            'Session',
            'prg_token_regex',
            '/^[\da-fA-F]{64}$/'
        );
        assert(is_string($prgTokenRegex));
        assert(@preg_match($prgTokenRegex, '') !== false);

        // -------- Request + canonical bag --------
        //$request = $this->injector->getClass(Request::class)->unwrap();
        $request = Request::fromGlobals();
        assert($request instanceof Request);

        $current = new CurrentUrl();

        $sid = null;
        if ($urlRewrite) {
            $requestUri = ($_SERVER['REQUEST_URI']??'/');
            $stack = UrlStack::fromRequest($requestUri, $basePath);

            $val = $stack->pop();
            if ($val !== null && in_array($val, $availableLocales, true)) {
                $locale = $val;
                $val = $stack->pop();
                $current->set($localeKey, $val);
                $request->query()->set($localeKey, $val);
            } else {
                $locale = $defaultLocale;
            }

            if (!$sessionUseCookies &&
                preg_match($sessionIdRegex, $val) === 1) {
                $sid = $val;
                $current->set($sessionKey, $val);
                $request->query()->set($sessionKey, $val);
                $val = $stack->pop();
            }

            // page token
            $pageToken = $val;
        } else {
            // classic mode
            $locale = $request->query()->get($localeKey);
            $pageToken = $request->query()->get($pageKey);

            if ($locale !== null) {
                $current->set($localeKey, $locale);
                if (!in_array($locale, $availableLocales, true)) {
                    $locale = $defaultLocale;
                }
            }

            if (!$sessionUseCookies) {
                $sid = $request->query()->get($sessionKey);
            }
        }

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
        $fetchAssoc = $this->config->getConfig(
            'PDO',
            'default_fetch_assoc',
            true
        );
        assert(is_bool($fetchAssoc));

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        $pdo->setAttribute(
            PDO::ATTR_ERRMODE,
            $errExc ? PDO::ERRMODE_EXCEPTION :
                PDO::ERRMODE_SILENT
        );
        $pdo->setAttribute(
            PDO::ATTR_DEFAULT_FETCH_MODE,
            $fetchAssoc ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH
        );

        $this->injector->setClass($pdo);

        $sessionHandler = $this->injector->createClass(
                SecureSessionHandler::class
            )->drainTo($this->collector)->unwrap();
        assert($sessionHandler instanceof SecureSessionHandler);

        session_set_save_handler($sessionHandler, true);

        if (!$sessionUseCookies) {
            if ($sid !== null) {
                if ($sessionHandler->validateId($sid)) {
                    session_id($sid);
                } else {
                    // Ban? LMAO or maybe log diagnostic?
                    assert(true);
                }
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sid = session_id();
        if (!$sessionUseCookies) {
            $current->set($sessionKey, $sid);
        }

        $pageToken = $pageToken??$defaultPageToken;
        $current->set($pageKey, $pageToken);
        if ($urlRewrite) {
            $request->query()->set($pageKey, $pageToken);
        }

        // here we have $locale, $sid and $pageToken.
        $this->translator->setLocale($locale);
        $this->moduleLoader->setLocale($locale);

        $prgHandler = $this->injector->getClass(PrgHandler::class)->unwrap();
        assert($prgHandler instanceof PrgHandler);

        if ($request->body()->all() !== [] && $request->body()->has('prg_id')) {
            $prgId = $request->body()->getString('prg_id');

            if ($prgId === null || !$prgHandler->hasTarget($prgId)) {
                throw new RuntimeException('Invalid PRG id.');
            }

            $token = $prgHandler->storeFromPayload($request->body()->all());

            Response::redirect($prgHandler->getUrl($prgId, $token))->send();
            exit;
        }

        $pageHandler = $this->injector->createClass(PageHandler::class)
            ->drainTo($this->collector)
            ->unwrap();
        assert($pageHandler instanceof PageHandler);

        $page = null;
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

        if ($page === null) {
            $id = $pageHandler->getPageIdFromUrlId($pageToken);
            $page = $id !== null ? $pageHandler->getPage($id) : null;
        }

        if ($page === null || $page->hidden) {
            http_response_code(404);

            $errorUrlId = $this->config->getConfig(
                'ContentManager',
                'error_page_url_id',
                'WORDING_ERROR'
            );
            assert(is_string($errorUrlId));

            $eid = $pageHandler->getPageIdFromUrlId($errorUrlId);
            $page = $eid !== null ? $pageHandler->getPage($eid) : null;

            if ($page === null) {
                $page = $pageHandler->getFallbackErrorPage($errorUrlId);
            }
        }
        assert($page instanceof Page);
        $this->injector->setClass($page);

        if ($page->controller) {
            $class = str_replace('_', '', ucwords($page->fileName, '_')) .
                     'Controller';
            if (!class_exists($class)) {
                assert(true);
                // this is bad.. what do we do?
                // log an error?
            } else {
                $controller = $this->injector->createClass($class)->drainTo(
                    $this->collector
                )->unwrap();

                if ($controller instanceof Controller) {
                    $r = $controller->handle()->drainTo($this->collector);
                    if (!$r->isOk()) {
                        // Controller fatal -> force 500 page (or 404, your choice)
                        http_response_code(500);
                        // You can resolve an error page here if you want.
                    }
                }
            }
        }

        if ($page->template) {
            $engine = $this->injector->createClass(TemplateEngine::class)
                ->drainTo($this->collector)
                ->unwrap();
            assert($engine instanceof TemplateEngine);

            $templateName = $page->templateFileName !== '' ?
                $page->templateFileName :
                (string)$this->config->getConfig(
                    'ContentManager',
                    'default_template',
                    'default'
                );

            $tpl = $engine->loadTemplate($templateName);
            if ($tpl === null || !method_exists($tpl, 'render')) {
                http_response_code(500);
            } else {
                echo $tpl->render($this->template_args);
            }
        } else {
            // no template has been set, and the controller somehow failed,
            // result: we are here!
            http_response_code(http_response_code() ?: 204);
        }

        echo "test :3";
    }
}