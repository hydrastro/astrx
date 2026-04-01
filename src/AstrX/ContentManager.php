<?php
declare(strict_types=1);

namespace AstrX;

use AstrX\Auth\Gate;
use AstrX\Auth\Permission;
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
use AstrX\Page\Diagnostic\PageHiddenNoticeDiagnostic;
use AstrX\Page\PageHandler;
use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticRenderer;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Routing\CurrentUrl;
use AstrX\Routing\UrlStack;
use AstrX\Session\Diagnostic\InvalidPrgIdDiagnostic;
use AstrX\Session\CommentPrgHandler;
use AstrX\Http\UploadedFile;
use AstrX\Session\PrgHandler;
use AstrX\Session\SecureSessionHandler;
use AstrX\User\UserGroup;
use AstrX\User\UserSession;
use AstrX\Template\DefaultTemplateContext;
use AstrX\Template\TemplateEngine;
use PDO;
use function AstrX\Support\langDir;

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
        private readonly Gate $gate,
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
        $availableLocales = array_values(array_filter($availableLocales, 'is_string'));

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

        $this->initPDO();

        $sessionResult = $this->injector->createClass(SecureSessionHandler::class)
            ->drainTo($this->collector);

        if (!$sessionResult->isOk()) {
            $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
            return;
        }

        /** @var SecureSessionHandler $sessionHandler */
        $sessionHandler = $sessionResult->unwrap();

        session_set_save_handler($sessionHandler, true);

        if (!$sessionUseCookies && $sidCandidate !== null) {
            if ($sessionHandler->validateId($sidCandidate)) {
                session_id($sidCandidate);
            }
        }

        // Harden session cookie: HttpOnly (no JS access), SameSite=Lax (CSRF
        // mitigation). The Secure flag is set only when the request is over HTTPS
        // so that local HTTP development still works. In production behind a TLS
        // terminator, $_SERVER['HTTPS'] is set to 'on' by the web server or by the
        // X-Forwarded-Proto header (if your proxy sets it).
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
                    && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $existingParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $existingParams['lifetime'],
            /** @phpstan-ignore notIdentical.alwaysTrue */
            'path'     => $existingParams['path'] !== '' ? $existingParams['path'] : '/',
            'domain'   => $existingParams['domain'],
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sid = (string) session_id();
        $current->set($sessionKey, $sid);
        $request->query()->set($sessionKey, $sid);

        // ── Session ID regeneration ───────────────────────────────────────────
        // Regenerate the session ID on privilege changes (login/logout/role change)
        // and on a time-based interval configurable per UserGroup.
        $this->maybeRegenerateSession($sessionHandler);

        // Update $sid after possible regeneration
        $sid = (string) session_id();
        $current->set($sessionKey, $sid);
        $request->query()->set($sessionKey, $sid);

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

        if ($request->body()->all() !== [] && $request->body()->has('prg_id')) {
            $prgIdResult = $request->body()->getString('prg_id')->drainTo($this->collector);
            $prgIdRaw = $prgIdResult->valueOr(null);
            $prgId = is_string($prgIdRaw) ? $prgIdRaw : null;

            // Comment forms include '_comment=1' in their body — route them through
            // CommentPrgHandler (separate session namespace + _cp query key) so that
            // other page controllers cannot steal the token before CommentController runs.
            $isCommentForm = $request->body()->has('_comment');

            if ($isCommentForm) {
                $commentPrg = new \AstrX\Session\CommentPrgHandler();
                if ($prgId === null || !$commentPrg->hasTarget($prgId)) {
                    $this->collector->emit(new InvalidPrgIdDiagnostic(
                                               self::ID_INVALID_PRG_ID,
                                               self::LVL_INVALID_PRG_ID,
                                               $prgId,
                                           ));
                    $this->renderError(HttpStatus::BAD_REQUEST);
                    return;
                }
                $token = $commentPrg->storeFromPayload($request->body()->all());
                $sendResult = Response::redirect($commentPrg->getUrl($prgId, $token))->send()
                    ->drainTo($this->collector);
            } else {
                if ($prgId === null || !$prgHandler->hasTarget($prgId)) {
                    $this->collector->emit(new InvalidPrgIdDiagnostic(
                                               self::ID_INVALID_PRG_ID,
                                               self::LVL_INVALID_PRG_ID,
                                               $prgId,
                                           ));
                    $this->renderError(HttpStatus::BAD_REQUEST);
                    return;
                }
                // Persist uploaded files through the PRG cycle: move each file to a
                // persistent temp path and store its metadata in __files__ so the
                // GET side can reconstruct UploadedFile objects before routing.
                $payload = $request->body()->all();
                $fileMeta = [];
                foreach ($request->files()->all() as $fieldName => $uploadedFile) {
                    if (!$uploadedFile instanceof UploadedFile || $uploadedFile->hasError()) {
                        continue;
                    }
                    $tmpDest = sys_get_temp_dir() . '/astrx_upload_' . bin2hex(random_bytes(8));
                    if (move_uploaded_file($uploadedFile->tempPath(), $tmpDest)) {
                        $fileMeta[(string) $fieldName] = [
                            'client_filename'   => $uploadedFile->clientFilename(),
                            'client_media_type' => $uploadedFile->clientMediaType(),
                            'temp_path'         => $tmpDest,
                            'size'              => $uploadedFile->size(),
                        ];
                    }
                }
                if ($fileMeta !== []) {
                    $payload['__files__'] = $fileMeta;
                }
                $token = $prgHandler->storeFromPayload($payload);
                $sendResult = Response::redirect($prgHandler->getUrl($prgId, $token))->send()
                    ->drainTo($this->collector);
            }
            if (!$sendResult->isOk()) {
                $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                return;
            }
            exit;
        }

        // Restore uploaded files that were persisted through the PRG cycle.
        // ContentManager stored them as __files__ in the PRG payload on POST.
        // We peek at the payload here (without consuming it) and inject the
        // reconstructed UploadedFile objects into the request FileBag so
        // controllers can read them via $request->files() as normal.
        $prgTokenForFiles = $request->query()->get($prgHandler->tokenQueryKey());
        if (is_string($prgTokenForFiles) && $prgTokenForFiles !== '') {
            $peeked = $prgHandler->get($prgTokenForFiles);
            if (is_array($peeked) && isset($peeked['__files__'])) {
                $filesRaw = $peeked['__files__'];
                {
                    /** @var array<string,array<string,mixed>> $filesArr */
                    $filesArr = $filesRaw;
                    foreach ($filesArr as $fieldName => $rawMeta) {
                        /** @var array<string,mixed> $meta */
                        $meta = $rawMeta;
                        if (!array_key_exists('temp_path', $meta)) { continue; }
                        $tmpPathRaw = $meta['temp_path'] ?? '';
                        if (!is_string($tmpPathRaw) || !file_exists($tmpPathRaw)) { continue; }
                        $tmpPath   = $tmpPathRaw;
                        $clientFnR = $meta['client_filename'] ?? '';
                        $clientFn  = is_string($clientFnR) ? $clientFnR : '';
                        $mediaTypeR = $meta['client_media_type'] ?? 'application/octet-stream';
                        $mediaType = is_string($mediaTypeR) ? $mediaTypeR : 'application/octet-stream';
                        $szR = $meta['size'] ?? 0;
                        $sz  = is_int($szR) ? $szR : 0;
                        $request->files()->set(
                            (string)$fieldName,
                            UploadedFile::fromTempPath($clientFn, $mediaType, $tmpPath, $sz),
                        );
                    }
                }
            }
        }

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
        $adminViewingHidden = $page->hidden && $this->gate->can(Permission::ADMIN_ACCESS);
        if (!$adminViewingHidden && $page->hidden) {
            http_response_code(HttpStatus::NOT_FOUND->value);
            $errorUrlId = $this->config->getConfig('ContentManager','error_page_url_id','WORDING_ERROR');
            assert(is_string($errorUrlId));
            $eid  = $pageHandler->getPageIdFromUrlId($errorUrlId);
            $page = ($eid !== null ? $pageHandler->getPage($eid) : null) ?? $page;
            $this->injector->setClass($page);
        }

        // ── Admin page guard ──────────────────────────────────────────────────────
        // All pages that are descendants of the admin root require ADMIN_ACCESS.
        // We check file_name (never translated, never editable via the Pages UI)
        // rather than url_id (translated slug that could theoretically change).
        // The admin root's file_name is 'admin'; all its descendants include it
        // as an ancestor in the closure table.
        $isAdminPage = $page->fileName === 'admin'
                       || array_any(
                           $page->ancestors,
                           fn($a) => $a['file_name'] === 'admin'
                       );
        if ($isAdminPage && $this->gate->cannot(Permission::ADMIN_ACCESS)) {
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

        // Load lang files for the current page and all its ancestors (bottom-up order,
        // so more-specific pages override ancestor values where keys overlap).
        // This replaces the old 'extra_lang_domains' config list — just add pages to the
        // hierarchy in the DB and their lang files are loaded automatically.
        // e.g. login → ancestor 'user' → loads User.en.php automatically.
        // Must happen before DefaultTemplateContext::buildBase() so that title/description
        // and keyword translations are already in the catalog.
        $langDir = langDir();

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
        $ctx->buildBase($page);
        if ($adminViewingHidden) {
            $this->collector->emit(new PageHiddenNoticeDiagnostic(
                                       'astrx.content/page_hidden', DiagnosticLevel::NOTICE
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
            $ctx->set('navbar', $navbarHandler->getNavbarEntries($navbarId, $page->ancestors));

            // User navbar (id=2) and admin navbar (id=3) are also DB-driven.
            // DefaultTemplateContext::finalise() reads these from ctx vars instead of
            // hardcoding the entries, so the admin can manage them via the navbar editor.
            $userNavbarId  = $this->config->getConfigInt('ContentManager', 'user_navbar_id',  2);
            $adminNavbarId = $this->config->getConfigInt('ContentManager', 'admin_navbar_id', 3);
            $ctx->set('db_user_nav',  $navbarHandler->getNavbarEntries($userNavbarId,  $page->ancestors));
            $ctx->set('db_admin_nav', $navbarHandler->getNavbarEntries($adminNavbarId, $page->ancestors));
        }

        if ($page->controller) {
            $short = str_replace('_', '', ucwords($page->fileName, '_')) . 'Controller';
            $fqcn  = 'AstrX\\Controller\\' . $short;

            if (class_exists($fqcn)) {
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
                http_response_code(HttpStatus::INTERNAL_SERVER_ERROR->value);
            }
        }

        // Dispatch the comment controller if comments are enabled on this page.
        // This runs AFTER the main controller so it can see any vars already set.
        if ($page->comments) {
            // Load Comment lang domain — ModuleLoader would look for
            // CommentController.en.php (class short name), not Comment.en.php.
            $this->translator->loadDomain(langDir(), 'Comment');
            $commentFqcn   = 'AstrX\\Controller\\CommentController';
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

            // Resolve deferred pagination URLs (SubPageState + CommentState → URLs).
            // Must happen before comments are pre-rendered because comments.html
            // references comments_filter_action, comments_has_pagination etc.
            $ctx->resolveUrls();

            // Render the comments partial into a ctx variable so it lands
            // inside the template (inside #main) rather than after </html>.
            if ($page->comments) {
                $ctx->set('page_comments', true);
                // Pre-render the partial so it is available as {{&comments_html}}
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

            $renderResult = $engine->renderTemplate($templateName, $ctx->all())
                ->drainTo($this->collector);

            if (!$renderResult->isOk()) {
                $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
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

    /**
     * @param list<string> $availableLocales
     * @return array{Locale, ?string, string}
     */
    private function parseRoutingHead(
        bool $urlRewrite,
        Request $request,
        string $basePath,
        /** @param list<string> $availableLocales */
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
            $uriRaw = $_SERVER['REQUEST_URI'] ?? '/';
            $requestUri = is_string($uriRaw) ? $uriRaw : '/';
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


    // =========================================================================
    // Error rendering
    // =========================================================================

    /**
     * Set the response code, load the error page, and render it.
     *
     * This replaces bare `http_response_code(X); return;` patterns throughout
     * the request pipeline so that errors produce a useful rendered page
     * instead of a blank response.
     *
     * Falls back to a minimal inline HTML page if the full error page machinery
     * is itself unavailable (e.g. DB down, template missing).
     */
    private function renderError(HttpStatus $status): void
    {
        http_response_code($status->value);

        // Load the Http lang domain so ErrorController has its translations.
        if (langDir() !== '') {
            $this->translator->loadDomain(langDir(), 'Http');
        }

        // Try the full error page route.
        $errorUrlId = $this->config->getConfig(
            'ContentManager', 'error_page_url_id', 'WORDING_ERROR'
        );
        if (!is_string($errorUrlId)) {
            $errorUrlId = 'WORDING_ERROR';
        }

        $phResult = $this->injector->getClass(\AstrX\Page\PageHandler::class);
        if ($phResult->isOk()) {
            /** @var \AstrX\Page\PageHandler $ph */
            $ph  = $phResult->unwrap();
            $eid = $ph->getPageIdFromUrlId($errorUrlId);
            $errorPage = $eid !== null ? $ph->getPage($eid) : null;

            if ($errorPage !== null) {
                $this->injector->setClass($errorPage);
                $this->renderErrorPage($errorPage);
                return;
            }
        }

        // Failsafe: minimal HTML that does not require templates or DB.
        $code = $status->value;
        $name = htmlspecialchars($status->name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = ucwords(strtolower(str_replace('_', ' ', $name)));
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="UTF-8"><title>{$code} {$name}</title></head>
        <body>
          <h1>{$code} — {$name}</h1>
          <p>An error occurred. Please try again or contact the administrator.</p>
        </body>
        </html>
        HTML;
    }


    /**
     * Render a single page (used by renderError to display the error page).
     * Stripped-down version of the inline render flow in init().
     */
    private function renderErrorPage(Page $page): void
    {
        $langDir = langDir();
        $this->translator->loadDomain($langDir, ucfirst($page->fileName));

        $ctxResult = $this->injector->createClass(DefaultTemplateContext::class)
            ->drainTo($this->collector);
        if (!$ctxResult->isOk()) { return; }
        /** @var DefaultTemplateContext $ctx */
        $ctx = $ctxResult->unwrap();
        $ctx->buildBase($page);

        if ($page->controller) {
            $short = str_replace('_', '', ucwords($page->fileName, '_')) . 'Controller';
            $fqcn  = 'AstrX\\Controller\\' . $short;
            if (class_exists($fqcn)) {
                $controllerResult = $this->injector->createClass($fqcn)
                    ->drainTo($this->collector);
                if ($controllerResult->isOk()) {
                    $controller = $controllerResult->unwrap();
                    if ($controller instanceof Controller) {
                        $controller->handle()->drainTo($this->collector);
                    }
                }
            }
        }

        if ($page->template) {
            $engineResult = $this->injector->createClass(TemplateEngine::class)
                ->drainTo($this->collector);
            if (!$engineResult->isOk()) { return; }
            /** @var TemplateEngine $engine */
            $engine = $engineResult->unwrap();
            $templateName = $this->config->getConfigString(
                'ContentManager', 'default_template', 'default'
            );
            $ctx->resolveUrls();
            $ctx->set('page_comments', false);
            $ctx->set('comments_html', '');
            $ctx->finalise();
            $renderResult = $engine->renderTemplate($templateName, $ctx->all())
                ->drainTo($this->collector);
            if ($renderResult->isOk()) {
                echo $renderResult->unwrap();
            }
        }
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
    private function maybeRegenerateSession(SecureSessionHandler $handler): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $forceRegen = ($_SESSION['_regen_force'] ?? false) === true;
        unset($_SESSION['_regen_force']);

        if (!$forceRegen && !$this->isTimeBasedRegenDue()) {
            return;
        }

        $oldSid      = (string) session_id();
        $oldHashedId = $handler->hashIdPublic($oldSid);

        // Keep the old row so in-flight requests can still find it.
        session_regenerate_id(false);

        $newSid      = (string) session_id();
        $newHashedId = $handler->hashIdPublic($newSid);

        $handler->markReplaced($oldHashedId, $newHashedId);

        $_SESSION['_regen_at'] = time();
    }

    private function isTimeBasedRegenDue(): bool
    {
        $loggedIn = ($_SESSION['logged_in'] ?? false) === true;
        $groupKey = 'GUEST';

        if ($loggedIn) {
            $userData = $_SESSION['user'] ?? null;
            if (is_array($userData)) {
                /** @var array<string,mixed> $userData */
                $typeRaw  = $userData['type'] ?? UserGroup::GUEST->value;
                $type     = is_int($typeRaw) ? $typeRaw
                    : (is_numeric($typeRaw) ? (int)(string)$typeRaw : UserGroup::GUEST->value);
                $group    = UserGroup::tryFrom($type) ?? UserGroup::GUEST;
                $groupKey = $group->name;
            }
        }

        /** @var mixed $rawConfig */
        $rawConfig = $this->config->getConfig('Session', 'regenerate_interval', []);
        if (!is_array($rawConfig)) {
            return false;
        }
        /** @var array<string,mixed> $regenConfig */
        $regenConfig = $rawConfig;

        $rawInterval = $regenConfig[$groupKey] ?? $regenConfig['default'] ?? 0;
        $interval    = is_int($rawInterval) ? $rawInterval
            : (is_numeric($rawInterval) ? (int)(string)$rawInterval : 0);

        if ($interval <= 0) {
            return false;
        }

        $lastRaw   = $_SESSION['_regen_at'] ?? 0;
        $lastRegen = is_int($lastRaw) ? $lastRaw
            : (is_numeric($lastRaw) ? (int)(string)$lastRaw : 0);

        return (time() - $lastRegen) >= $interval;
    }

}
