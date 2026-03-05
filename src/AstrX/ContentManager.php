<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Config\Config;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Result\DiagnosticsCollector;
use AstrX\I18n\Translator;
use AstrX\Routing\UrlStack;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\Request;
use PDO;

final class ContentManager
{
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
        // routing config
        $urlRewrite = $this->config->getConfig('Routing', 'url_rewrite', true);
        assert(is_bool($urlRewrite));

        $basePath = $this->config->getConfig('Routing', 'base_path', '/');
        assert(is_string($basePath));

        $availableLocales = $this->config->getConfig('Prelude', 'available_languages', ['en']);
        assert(is_array($availableLocales));

        $defaultLocale = $this->config->getConfig('Prelude', 'default_language', 'en');
        assert(is_string($defaultLocale));

        $sessionUseCookies = $this->config->getConfig('Session', 'use_cookies', true);
        assert(is_bool($sessionUseCookies));

        $sessionIdRegex = $this->config->getConfig('Session', 'session_id_regex', '/^[\da-fA-F]{64,256}$/');
        assert(is_string($sessionIdRegex));
        assert(@preg_match($sessionIdRegex, '') !== false); // regex compiles

        $localeKey  = $this->config->getConfig('Routing', 'locale_key', 'lang');
        assert(is_string($localeKey));
        $sessionKey = $this->config->getConfig('Routing', 'session_key', 'sid');
        assert(is_string($sessionKey));
        $pageKey    = $this->config->getConfig('Routing', 'page_key', 'page');
        assert(is_string($pageKey));

        $defaultPage = $this->config->getConfig('Routing', 'default_page', 'main');
        assert(is_string($defaultPage));

        $request = $this->injector->getClass(Request::class)->unwrap();
        assert($request instanceof Request);

        $currentUrl = new CurrentUrl();

        if ($urlRewrite) {
            $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $stack = UrlStack::fromRequest($requestUri, $basePath);

            // HEAD parsing: [lang]? [sid]? page
            $first = $stack->pop();
            $lang = $defaultLocale;

            if ($first !== null && in_array($first, $availableLocales, true)) {
                $lang = $first;
            } else {
                // if first is not a locale, it's either sid or page. push it back logically:
                // easiest: treat it as "pageCandidate"
                $pageCandidate = $first;
                $first = null;
            }

            $currentUrl->set($localeKey, $lang);

            // sid
            $sid = null;
            $next = $stack->pop();

            if (!$sessionUseCookies && $next !== null && preg_match($sessionIdRegex, $next) === 1) {
                $sid = $next;
                $next = $stack->pop();
            }

            if ($sid !== null) {
                $currentUrl->set($sessionKey, $sid);
            }

            // page
            $page = $next ?? ($pageCandidate ?? null) ?? $defaultPage;
            $currentUrl->set($pageKey, $page);

            // Now configure Request to read from CurrentUrl in rewrite mode
            $request->configureRewrite(true, $currentUrl);
        } else {
            // QUERY MODE: lang key is canonical and not translated
            $lang = $request->get($localeKey, $defaultLocale);
            assert(is_string($lang));
            if (!in_array($lang, $availableLocales, true)) {
                $lang = $defaultLocale;
            }
            $this->translator->setLocale($lang);

            // just ensure canonical keys exist for downstream code
            $currentUrl->set($localeKey, $lang);

            if (!$sessionUseCookies) {
                $sid = $request->get($sessionKey, null);
                if (is_string($sid) && preg_match($sessionIdRegex, $sid) === 1) {
                    $currentUrl->set($sessionKey, $sid);
                }
            }

            $page = $request->get($pageKey, $defaultPage);
            assert(is_string($page));
            $currentUrl->set($pageKey, $page);

            // In query mode, Request keeps reading from $_GET; but we still keep CurrentUrl
            // as the canonical bag for URL building later if you want.
            $request->configureRewrite(false, null);
        }

        // Locale is now known
        $lang = $currentUrl->get($localeKey, $defaultLocale);
        assert(is_string($lang));
        // If Translator::setLocale returns bool in your code, keep this pattern:
        $this->translator->setLocale($lang);
        $this->moduleLoader->setLocale($lang);

        // Setup DB (safe to do now)
        $this->setupPdo();

        $prgKey = $this->config->getConfig('Session', 'prg_key', 'prg');
        assert(is_string($prgKey));

        $this->setupSessionAndPrg(
            request: $request,
            currentUrl: $currentUrl,
            sessionUseCookies: $sessionUseCookies,
            sessionKey: $sessionKey,
            sessionIdRegex: $sessionIdRegex,
            prgKey: $prgKey
        );
        // Next steps: setup session, resolve page from DB, controller chain, render, etc.
        // (we'll do those after routing is stable)
        echo "KEK";
    }

    private function setupPdo(): void
    {
        $this->config->loadModuleConfig('PDO');

        $dsn = $this->config->getConfig("PDO", "db_type", "mysql");
        assert(is_string($dsn));
        $host = $this->config->getConfig("PDO", "db_host", "mariadb");
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

        $emulateParameters = $this->config->getConfig('PDO', 'emulate_prepares', false);
        assert(is_bool($emulateParameters));
        $errmodeException = $this->config->getConfig('PDO', 'errmode_exception', true);
        assert(is_bool($errmodeException));
        $fetchAssoc = $this->config->getConfig('PDO', 'default_fetch_assoc', true);
        assert(is_bool($fetchAssoc));

        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulateParameters);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, $errmodeException ? PDO::ERRMODE_EXCEPTION : PDO::ERRMODE_SILENT);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $fetchAssoc ? PDO::FETCH_ASSOC : PDO::FETCH_BOTH);

        $this->injector->setClass($pdo);
    }

    private function setupSessionAndPrg(
        Request $request,
        CurrentUrl $currentUrl,
        bool $sessionUseCookies,
        string $sessionKey,
        string $sessionIdRegex,
        string $prgKey
    ): void {
        // Install handler if you have SecureSessionHandler in your project:
        // $handler = $this->injector->getClass(\AstrX\SecureSessionHandler\SecureSessionHandler::class)->unwrap();
        // session_set_save_handler($handler, true);

        // If cookies disabled, accept SID from routing/currentUrl.
        if (!$sessionUseCookies) {
            $sid = $currentUrl->get($sessionKey, '');
            if (is_string($sid) && $sid !== '' && preg_match($sessionIdRegex, $sid) === 1) {
                session_id($sid);
            }
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Ensure canonical SID is set after session_start().
        $currentUrl->set($sessionKey, (string)session_id());

        // PRG handling
        /** @var PostRedirectGet $prg */
        $prg = $this->injector->getClass(PostRedirectGet::class)->unwrap();
        assert($prg instanceof PostRedirectGet);

        // 1) If POST: store + redirect
        if ($_POST !== []) {
            $data = serialize($_POST);
            $sid = (string)session_id();
            $token = hash_hmac('sha256', $data, $sid);

            $prg->store($token, $_POST);

            // Build redirect URL with token.
            // For now (minimal), use query mode redirect even in rewrite mode.
            // Later you can unify with your Url builder.
            $qs = http_build_query([$prgKey => $token], '', '&');
            $location = ($_SERVER['REQUEST_URI'] ?? '/');
            $location = explode('?', $location, 2)[0] . '?' . $qs;

            header('Location: ' . $location, true, 302);
            exit;
        }

        // 2) If GET has token: load + merge into $_POST
        $token = $request->get($prgKey, null);
        if (is_string($token) && $token !== '') {
            $data = $prg->load($token);
            if ($data !== []) {
                $_POST = array_merge($_POST, $data);
            }
            // Optional: clear one-time token
            $prg->clear($token);
        }
    }
}