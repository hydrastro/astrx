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
use AstrX\I18n\Translator;
use AstrX\Injector\Injector;
use AstrX\Module\ModuleLoader;
use AstrX\Module\ModuleRegistry;
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
        $astrxRequestStarted = microtime(true);
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

        // A locale is a plain string validated against available_languages
        // (config), NOT a fixed enum — so an admin can add a language from the
        // Language admin page without a code change. Fall back to the first
        // available locale, then 'en'.
        $defaultLocale = in_array($defaultLocaleStr, $availableLocales, true)
            ? $defaultLocaleStr
            : ($availableLocales[0] ?? 'en');

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

        // Bearer/API requests must be STATELESS: never mint or reuse a browser
        // cookie session for them. Otherwise a cookie-jar client that sends the
        // key once keeps an authenticated session cookie that (a) then works
        // WITHOUT the key and (b) survives key revocation/expiry — defeating the
        // credential's primary control. Detect the bearer before session_start,
        // ignore any inbound session cookie (so a bearer call can't hijack or
        // overwrite the caller's own browser session), and suppress Set-Cookie
        // via the ini flags set just below.
        $bearerPresent = (($request->bearerToken() ?? '') !== '');
        if ($bearerPresent) {
            unset($_COOKIE[session_name()]);
        }

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
        // X-Forwarded-Proto is client-settable, so only honour it when the operator
        // has declared a trusted TLS-terminating proxy. Default off → the Secure
        // flag follows the real connection only. This matters on a plain-HTTP
        // hidden service, where a spoofed header would otherwise set Secure and
        // stop the session cookie being sent at all.
        $trustFwdProto = $this->config->getConfig('ContentManager', 'trust_forwarded_proto', false) === true;
        $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || ($trustFwdProto
                    && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
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
            // Enforce strict session mode regardless of php.ini so an
            // uninitialised/attacker-supplied session ID is never adopted.
            // This activates SecureSessionHandler::validateId() on the incoming ID.
            ini_set('session.use_strict_mode', '1');
            // Genuinely honour a cookieless deployment (php.ini ships
            // use_cookies=1, so the mode was silently still emitting cookies), and
            // force cookieless for stateless bearer requests — no Set-Cookie is
            // emitted in either case.
            if (!$sessionUseCookies || $bearerPresent) {
                ini_set('session.use_cookies', '0');
                ini_set('session.use_only_cookies', '0');
            }
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

        // ── Bearer / API-key authentication ───────────────────────────────────
        // Must run AFTER session_start so the identity survives, and after
        // regeneration so a stateless API call doesn't rotate a guest session.
        // loginFromApiKey() omits _regen_force, so it triggers no further regen.
        $bearer = $request->bearerToken();
        if ($bearer !== null && $bearer !== '') {
            $apiKeyResult = $this->injector->createClass(\AstrX\Api\ApiKeyService::class)
                ->drainTo($this->collector);
            if ($apiKeyResult->isOk()) {
                /** @var \AstrX\Api\ApiKeyService $apiKeySvc */
                $apiKeySvc = $apiKeyResult->unwrap();
                $authedUserId = $apiKeySvc->validate($bearer);
                if ($authedUserId !== null) {
                    $request->setApiKeyUser($authedUserId);
                    $userRepoResult = $this->injector->createClass(\AstrX\User\UserRepository::class)
                        ->drainTo($this->collector);
                    $userSessResult = $this->injector->createClass(\AstrX\User\UserSession::class)
                        ->drainTo($this->collector);
                    if ($userRepoResult->isOk() && $userSessResult->isOk()) {
                        /** @var \AstrX\User\UserRepository $userRepo */
                        $userRepo = $userRepoResult->unwrap();
                        /** @var \AstrX\User\UserSession $userSess */
                        $userSess = $userSessResult->unwrap();
                        $userRow = $userRepo->findById($authedUserId);
                        $userRow->drainTo($this->collector);
                        if ($userRow->isOk()) {
                            $row = $userRow->unwrap();
                            // A closed account (keep_visible keeps deleted=0 for
                            // content visibility) must not authenticate via its API
                            // key either — password login already blocks non-'none'
                            // deletion_mode; mirror that on the bearer path so a
                            // key can't outlive the account being closed.
                            $delMode = (is_array($row) && is_scalar($row['deletion_mode'] ?? null))
                                ? (string) $row['deletion_mode'] : '';
                            $accountOpen = ($delMode === '' || $delMode === 'none');
                            if (is_array($row) && !empty($row['id']) && empty($row['deleted']) && $accountOpen) {
                                /** @var array{id:string,username:string,display_name:string,type:int,verified:int|bool,avatar:int|bool,mailbox?:string,theme?:string|null} $row */
                                $userSess->loginFromApiKey($row);
                            }
                        }
                    }
                }
            }
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
                // ALWAYS overwrite the reserved __files__ key (even with []) so a
                // user-supplied __files__ (e.g. a crafted temp_path) can never
                // survive into the stored PRG payload and later reach @unlink (PRG
                // GC) or a file read via UploadedFile::fromTempPath.
                $payload['__files__'] = $fileMeta;
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
                foreach ($registry->pageGuards() as $guard) {
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
                    // The JS shell owns the full CSP; these two are pure-win
                    // anonymity headers on the fragment response and never clip it.
                    header('Referrer-Policy: no-referrer');
                    header('X-Content-Type-Options: nosniff');
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

                $rendererResult = $this->injector->createClass(\AstrX\Api\JsonRenderer::class)
                    ->drainTo($this->collector);
                if (!$rendererResult->isOk()) {
                    $this->renderError(HttpStatus::INTERNAL_SERVER_ERROR);
                    return;
                }
                /** @var \AstrX\Api\JsonRenderer $jsonRenderer */
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
            // Baseline security headers for the main HTML document (CSP etc.).
            // NOT emitted on the /js/ fragment path above — JsController owns a
            // more permissive CSP for the JS shell.
            $this->emitSecurityHeaders();
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

    /**
     * @param list<string> $availableLocales
     * @return array{string, ?string, string}
     */
    private function parseRoutingHead(
        bool $urlRewrite,
        Request $request,
        string $basePath,
        /** @param list<string> $availableLocales */
        array $availableLocales,
        string $defaultLocale,
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

            $localeFromUrl = ($a !== null && in_array($a, $availableLocales, true));
            $locale        = ($localeFromUrl && $a !== null) ? $a : $defaultLocale;

            // Only consume a SECOND segment when the first one was the locale.
            // With no locale prefix, $a IS the page token and every remaining
            // segment (login, page number, sort, …) must stay on the stack so
            // setTail() below can see it. The old code popped $b unconditionally
            // and then reassigned $b = $a, silently discarding the segment after
            // the page token on locale-less URLs — e.g. /user/login resolved to
            // page 'user' with an empty tail (login lost); /main/2 lost its page
            // number (R3-19).
            if ($localeFromUrl) {
                $b = $stack->pop();
            } else {
                $b = $a;
            }

            $current->set($localeKey, $locale);
            $request->query()->set($localeKey, $locale);

            // API detection (rewrite mode): the segment after the locale is
            // literally "api". /en/api/user-profile/... becomes an API call
            // resolving to the user-profile page. We strip the segment from
            // the stack so the rest of the routing logic sees the URL as if
            // /api/ wasn't there. The api_enabled check on the page itself
            // is what actually grants access.
            if ($b === 'api') {
                $request->markAsApi();
                $b = $stack->pop();   // advance to the real page token
            }

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
        $locale    = (is_string($rawLocale) && in_array($rawLocale, $availableLocales, true))
            ? $rawLocale
            : $defaultLocale;

        $current->set($localeKey, $locale);
        $request->query()->set($localeKey, $locale);

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

        // API detection (query mode): ?api=1 turns the same page into an
        // API call. Mirror of the rewrite-mode /api/ segment above.
        $apiFlag = $request->query()->get('api');
        if ($apiFlag === '1' || $apiFlag === 'true') {
            $request->markAsApi();
        }

        return [$locale, $sidCandidate, $pageToken];
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

    /**
     * Emit the baseline security headers for the main HTML document response.
     *
     * The canonical site is designed to run with JavaScript OFF, so a strict
     * Content-Security-Policy (default-src 'none') is safe and neutralises any
     * injected script/frame. The CSP is config-driven
     * (ContentManager.content_security_policy) so operators can relax it per
     * deployment without editing code; a hard-coded strict default is used when
     * the config key is absent or empty.
     *
     * No-op if headers have already been sent (e.g. a controller streamed bytes).
     */
    private function emitSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        $defaultCsp = "default-src 'none'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; "
                    . "frame-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

        $csp = $this->config->getConfigString(
            'ContentManager',
            'content_security_policy',
            $defaultCsp,
        );
        if ($csp === '') {
            $csp = $defaultCsp;
        }

        header('Content-Security-Policy: ' . $csp);
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
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

        // Error pages are full HTML documents too: give them the same CSP /
        // Referrer-Policy / nosniff / frame protections as the main render path.
        // Covers both the templated error page and the failsafe below (the helper
        // no-ops if headers were already sent).
        $this->emitSecurityHeaders();

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

        $port     = $this->config->getConfigInt('PDO', 'db_port', 0);
        $portPart = $port > 0 ? ';port=' . $port : '';
        $dsn = $driver . ':host=' . $host . $portPart . ';dbname=' . $dbname . ';charset=utf8mb4';
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

        // Preserve an active remember-me expiry across regeneration — otherwise the
        // regenerated cookie reverts to a session-lifetime cookie and remember-me
        // is silently lost on the request after login (R3-13).
        $rememberUntil = $_SESSION['_remember_until'] ?? 0;
        if (is_int($rememberUntil) && $rememberUntil > time()) {
            $params = session_get_cookie_params();
            setcookie((string) session_name(), (string) session_id(), [
                'expires'  => $rememberUntil,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
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
