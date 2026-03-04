<?php
declare(strict_types=1);

namespace AstrX\Routing;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Routing\Diagnostic\UndefinedQueryKeyDiagnostic;

final class Router
{
    public const ID_UNDEFINED_QUERY_KEY = 'astrx.routing/undefined_query_key';
    public const LVL_UNDEFINED_QUERY_KEY = DiagnosticLevel::WARNING;

    private ?DiagnosticSinkInterface $sink = null;

    public function __construct(
        private RoutingConfig $cfg,
        private QueryParamRegistry $registry
    ) {}

    public function setDiagnosticSink(?DiagnosticSinkInterface $sink): void
    {
        $this->sink = $sink;
    }

    /**
     * @param array<string,string> $query
     */
    public function parse(string $requestUri, array $query, RoutingContext $ctx): ParsedRoute
    {
        $state = new RouteState();

        if ($this->cfg->mode === UrlMode::REWRITE) {
            $stack = RouteStack::fromRequestUri($requestUri, $this->cfg->basePath);

            // Optional head: locale (if matches availableLocales)
            $locale = $ctx->defaultLocale;
            $peek = $stack->peek();
            if ($peek !== null && $ctx->isLocale($peek)) {
                $locale = (string)$stack->take();
            }
            $state->set($ctx->localeKey, $locale);

            // Optional head: session id (only when cookies disabled)
            $sid = null;
            if (!$ctx->sessionUseCookies) {
                $p = $stack->peek();
                if ($p !== null && $ctx->isSessionId($p)) {
                    $sid = (string)$stack->take();
                }
            }
            $state->set($ctx->sessionKey, $sid);

            // First required segment (page); default to main
            $page = $stack->take();
            $state->set($ctx->pageKey, $page ?? 'main');

            return new ParsedRoute($state, $stack);
        }

        // QUERY MODE

        // Determine locale from query, using the locale mapping from domain "config".
        $locale = $ctx->defaultLocale;
        $langExternal = $this->registry->externalKey($locale, ['config'], $ctx->localeKey) ?? $ctx->localeKey;

        if (isset($query[$langExternal]) && $ctx->isLocale($query[$langExternal])) {
            $locale = $query[$langExternal];
        }
        $state->set($ctx->localeKey, $locale);

        // Pass 1: map global keys (domain "config")
        foreach ($query as $extKey => $value) {
            $canon = $this->registry->canonicalKey($locale, ['config'], $extKey);
            if ($canon !== null) {
                $state->set($canon, $value);
            }
        }

        // Resolve page (fallback main)
        $pageExt = $this->registry->externalKey($locale, ['config'], $ctx->pageKey) ?? $ctx->pageKey;
        $page = $query[$pageExt] ?? 'main';
        $state->set($ctx->pageKey, $page);

        // Session in query if cookies disabled
        if (!$ctx->sessionUseCookies) {
            $sidExt = $this->registry->externalKey($locale, ['config'], $ctx->sessionKey) ?? $ctx->sessionKey;
            $state->set($ctx->sessionKey, $query[$sidExt] ?? null);
        }

        // Pass 2: map page-domain keys (domain is page id here)
        $pageDomain = $page;
        foreach ($query as $extKey => $value) {
            $canon = $this->registry->canonicalKey($locale, ['config', $pageDomain], $extKey);
            if ($canon !== null) {
                $state->set($canon, $value);
            }
        }

        return new ParsedRoute($state, new RouteStack([]));
    }

    /**
     * @param list<string> $pathSegments controller-built tail after the page
     */
    public function buildUrl(RouteState $state, array $pathSegments, RoutingContext $ctx): string
    {
        $locale = $state->get($ctx->localeKey, $ctx->defaultLocale) ?? $ctx->defaultLocale;
        $sid = $state->get($ctx->sessionKey, null);
        $page = $state->get($ctx->pageKey, 'main') ?? 'main';

        if ($this->cfg->mode === UrlMode::REWRITE) {
            $segments = [];

            // optional head locale (only if not default)
            if ($locale !== $ctx->defaultLocale) {
                $segments[] = $locale;
            }

            // optional head sid (if cookies disabled)
            if (!$ctx->sessionUseCookies && $sid !== null) {
                $segments[] = $sid;
            }

            $segments[] = $page;
            $segments = array_merge($segments, $pathSegments);

            $path = implode('/', array_map('rawurlencode', $segments)) . '/';
            return rtrim($this->cfg->basePath, '/') . '/' . $path;
        }

        // QUERY MODE
        $domains = ['config', $page];

        /** @var array<string,string> $externalQuery */
        $externalQuery = [];

        // locale
        $langExt = $this->registry->externalKey($locale, ['config'], $ctx->localeKey);
        if ($langExt === null) {
            $this->emitUndefinedQueryKey($locale, $ctx->localeKey);
            $langExt = $ctx->localeKey;
        }
        $externalQuery[$langExt] = $locale;

        // page
        $pageExt = $this->registry->externalKey($locale, ['config'], $ctx->pageKey);
        if ($pageExt === null) {
            $this->emitUndefinedQueryKey($locale, $ctx->pageKey);
            $pageExt = $ctx->pageKey;
        }
        $externalQuery[$pageExt] = $page;

        // sid in query if cookies disabled
        if (!$ctx->sessionUseCookies && $sid !== null) {
            $sidExt = $this->registry->externalKey($locale, ['config'], $ctx->sessionKey);
            if ($sidExt === null) {
                $this->emitUndefinedQueryKey($locale, $ctx->sessionKey);
                $sidExt = $ctx->sessionKey;
            }
            $externalQuery[$sidExt] = $sid;
        }

        // other canonical params externalized
        foreach ($state->all() as $canon => $val) {
            if ($val === null) continue;
            if ($canon === $ctx->localeKey || $canon === $ctx->pageKey || $canon === $ctx->sessionKey) continue;

            $ext = $this->registry->externalKey($locale, $domains, $canon);
            if ($ext === null) {
                $this->emitUndefinedQueryKey($locale, $canon);
                $ext = $canon;
            }
            $externalQuery[$ext] = $val;
        }

        $base = rtrim($this->cfg->basePath, '/') . '/' . ltrim($this->cfg->entryPoint, '/');
        $qs = http_build_query($externalQuery, '', '&');
        return $qs === '' ? $base : ($base . '?' . $qs);
    }

    private function emitUndefinedQueryKey(string $locale, string $canonicalKey): void
    {
        $this->sink?->emit(new UndefinedQueryKeyDiagnostic(
                               self::ID_UNDEFINED_QUERY_KEY,
                               self::LVL_UNDEFINED_QUERY_KEY,
                               $locale,
                               $canonicalKey
                           ));
    }
}