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
use AstrX\Page\Page;
use AstrX\Page\PageHandler;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlStack;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;
use AstrX\Session\PrgHandler;
use AstrX\Session\SecureSessionHandler;
use AstrX\Template\TemplateEngine;
use PDO;

final class ContentManager
{
    /** @var array<string,mixed> */
    public array $template_args = [];
    // ContentManager-owned diagnostic policy (IDs + levels live here, per your rule)
    public const string ID_INVALID_PRG_ID = 'astrx.session/invalid_prg_id';
    public const DiagnosticLevel LVL_INVALID_PRG_ID = DiagnosticLevel::WARNING;

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
        // --- load configs for modules that are not necessarily created early ---
        $this->config->loadModuleConfig('Routing');
        $this->config->loadModuleConfig('Session');
        $this->config->loadModuleConfig('ContentManager');
        $this->config->loadModuleConfig('PDO');

        // --- routing config ---
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

        $defaultPageToken = $this->config->getConfig(
            'Routing',
            'default_page',
            'main'
        );
        assert(is_string($defaultPageToken));

        // --- locale config ---
        $availableLocales = $this->config->getConfig(
            'Prelude',
            'available_languages',
            ['en']
        );
        assert(is_array($availableLocales));

        $defaultLocaleStr = $this->config->getConfig(
            'Prelude',
            'default_language',
            'en'
        );
        assert(is_string($defaultLocaleStr));

        $defaultLocale = Locale::fromStringOrDefault(
            $defaultLocaleStr,
            Locale::EN
        );
        if (!$defaultLocale->isAllowed($availableLocales)) {
            // if config is inconsistent, fall back safely
            $defaultLocale = Locale::EN;
        }

        // --- session config ---
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

        // --- request ---
        $request = Request::fromGlobals();
        assert($request instanceof Request);
        $this->injector->setClass($request);

        // --- canonical route bag ---
        $current = new CurrentUrl();

        // --- parse head (locale / sid / page) deterministically ---
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
            current:           $current
        );

        // locale is now known: set translator + module loader once
        $this->translator->setLocale($locale->value);
        $this->moduleLoader->setLocale($locale->value);

        // --- PDO (needed before SecureSessionHandler) ---
        $this->initPDO();

        // --- session handler ---
        $sessionHandler = $this->injector->createClass(
                SecureSessionHandler::class
            )->drainTo($this->collector)->unwrap();
        assert($sessionHandler instanceof SecureSessionHandler);

        session_set_save_handler($sessionHandler, true);

        // session id selection (only meaningful if cookies disabled)
        if (!$sessionUseCookies && $sidCandidate !== null) {
            if ($sessionHandler->validateId($sidCandidate)) {
                session_id($sidCandidate);
            } else {
                // fixation attempt: do nothing; new session will be created
                // you can add a diagnostic later if you want
                assert(true);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sid = (string)session_id();
        $current->set($sessionKey, $sid);
        $request->query()->set($sessionKey, $sid);

        // page token canonicalization
        $pageToken = ($pageToken === '' ? $defaultPageToken : $pageToken);
        $current->set($pageKey, $pageToken);
        $request->query()->set($pageKey, $pageToken);

        // --- PRG handling (you said PrgHandler is fine; just avoid throw) ---
        /** @var PrgHandler $prgHandler */
        $prgHandler = $this->injector->getClass(PrgHandler::class)->unwrap();
        assert($prgHandler instanceof PrgHandler);

        if ($request->body()->all() !== [] && $request->body()->has('prg_id')) {
            $prgId = $request->body()->getString('prg_id');

            if ($prgId === null || !$prgHandler->hasTarget($prgId)) {
                $this->collector->emit(
                    new InvalidPrgIdDiagnostic(
                        self::ID_INVALID_PRG_ID,
                        self::LVL_INVALID_PRG_ID,
                        $prgId
                    )
                );
                http_response_code(HttpStatus::BAD_REQUEST);

                return;
            }

            $token = $prgHandler->storeFromPayload($request->body()->all());
            Response::redirect($prgHandler->getUrl($prgId, $token))->send();
            exit;
        }

        // --- page resolve ---
        /** @var PageHandler $pageHandler */
        $pageHandler = $this->injector->createClass(PageHandler::class)
            ->drainTo($this->collector)
            ->unwrap();
        assert($pageHandler instanceof PageHandler);

        $page = $this->resolvePage($pageHandler, $pageToken);
        assert($page instanceof Page);
        $this->injector->setClass($page);

        // --- controller (optional) ---
        if ($page->controller) {
            $short = str_replace('_', '', ucwords($page->fileName, '_')) .
                     'Controller';
            $fqcn = 'AstrX\\Controller\\' . $short;

            if (class_exists($fqcn)) {
                $controller = $this->injector->createClass($fqcn)->drainTo(
                        $this->collector
                    )->unwrap();

                if ($controller instanceof Controller) {
                    $r = $controller->handle()->drainTo($this->collector);
                    if (!$r->isOk()) {
                        http_response_code(HttpStatus::INTERNAL_SERVER_ERROR);
                        // fall through to template if enabled
                    }
                }
            } else {
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR);
                // (optional) emit ControllerNotFound diagnostic later
            }
        }

        // --- template (optional) ---
        if ($page->template) {
            /** @var TemplateEngine $engine */
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
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR);

                return;
            }

            echo $tpl->render($this->template_args);

            return;
        }

        // no template: controller might have written output; otherwise 204/whatever already set
        http_response_code(http_response_code() ?: HttpStatus::NO_CONTENT);
    }

    /**
     * Returns: [Locale $locale, ?string $sidCandidate, string $pageToken]
     */
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
        CurrentUrl $current
    )
    : array {
        $sidCandidate = null;

        if ($urlRewrite) {
            $requestUri = (string)($_SERVER['REQUEST_URI']??'/');
            $stack = UrlStack::fromRequest($requestUri, $basePath);

            $a = $stack->pop(); // maybe locale OR sid OR page
            $b = $stack->pop(); // maybe sid OR page

            // locale
            $locale = ($a !== null && in_array($a, $availableLocales, true)) ?
                Locale::fromStringOrDefault($a, $defaultLocale) :
                $defaultLocale;

            // if $a wasn't locale, treat it as next token
            if (!($a !== null && in_array($a, $availableLocales, true))) {
                $b = $a;
            }

            $current->set($localeKey, $locale->value);
            $request->query()->set($localeKey, $locale->value);

            // sid/page discrimination
            if (!$sessionUseCookies &&
                $b !== null &&
                preg_match($sessionIdRegex, $b) === 1) {
                $sidCandidate = $b;
                $current->set($sessionKey, $sidCandidate);
                $request->query()->set($sessionKey, $sidCandidate);

                $pageToken = $stack->pop()??$defaultPageToken;
            } else {
                $pageToken = $b??$defaultPageToken;
            }

            $current->set($pageKey, $pageToken);
            $request->query()->set($pageKey, $pageToken);

            return [$locale, $sidCandidate, $pageToken];
        }

        // query mode
        $rawLocale = $request->query()->get($localeKey);
        $locale = Locale::fromStringOrDefault(
            is_string($rawLocale) ? $rawLocale : null,
            $defaultLocale
        );
        if (!$locale->isAllowed($availableLocales)) {
            $locale = $defaultLocale;
        }

        $current->set($localeKey, $locale->value);
        $request->query()->set($localeKey, $locale->value);

        if (!$sessionUseCookies) {
            $rawSid = $request->query()->get($sessionKey);
            if (is_string($rawSid) &&
                preg_match($sessionIdRegex, $rawSid) === 1) {
                $sidCandidate = $rawSid;
                $current->set($sessionKey, $sidCandidate);
                $request->query()->set($sessionKey, $sidCandidate);
            }
        }

        $rawPage = $request->query()->get($pageKey);
        $pageToken = (is_string($rawPage) && $rawPage !== '') ? $rawPage :
            $defaultPageToken;

        $current->set($pageKey, $pageToken);
        $request->query()->set($pageKey, $pageToken);

        return [$locale, $sidCandidate, $pageToken];
    }

    private function initPDO()
    : void
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
    }

    private function resolvePage(PageHandler $pageHandler, string $pageToken)
    : Page {
        // i18n map first
        $map = [];
        foreach ($pageHandler->getInternationalizedPageIds() as $row) {
            $urlId = (string)$row['url_id'];
            $pid = (int)$row['id'];
            $resolved = $this->translator->t($urlId);
            $map[$resolved] = $pid;
        }

        $page = null;

        if (isset($map[$pageToken])) {
            $page = $pageHandler->getPage($map[$pageToken]);
        }

        if ($page === null) {
            $id = $pageHandler->getPageIdFromUrlId($pageToken);
            $page = $id !== null ? $pageHandler->getPage($id) : null;
        }

        if ($page === null || $page->hidden) {
            http_response_code(404);

            // NOTE: you had a key mismatch before; use the config you actually have:
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

        return $page;
    }
}