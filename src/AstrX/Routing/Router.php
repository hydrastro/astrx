<?php

declare(strict_types = 1);

namespace AstrX\Routing;

use AstrX\Result\DiagnosticLevel;
use AstrX\Result\DiagnosticSinkInterface;
use AstrX\Routing\Diagnostics\UndefinedQueryKeyDiagnostic;

final class Router
{
    // Diagnostics policy (IDs + levels here)
    public const ID_UNDEFINED_QUERY_KEY = 'astrx.routing/undefined_query_key';
    public const LVL_UNDEFINED_QUERY_KEY = DiagnosticLevel::WARNING;
    private ?DiagnosticSinkInterface $sink = null;

    public function __construct(
        private RoutingConfig $cfg,
        private QueryParamRegistry $registry
    ) {
    }

    public function setDiagnosticSink(?DiagnosticSinkInterface $sink)
    : void {
        $this->sink = $sink;
    }

    /**
     * Parse request into (state, stack).
     * - rewrite: stack is remaining segments after consuming optional locale/session/page
     * - query: stack is empty; params come from query
     *
     * @param array<string,string> $query
     */
    public function parse(string $requestUri, array $query)
    : ParsedRoute {
        $state = new RouteState();

        if ($this->cfg->mode === UrlMode::REWRITE) {
            $stack = RouteStack::fromRequestUri(
                $requestUri,
                $this->cfg->basePath
            );

            // optional head: locale
            $locale = $this->cfg->defaultLocale;
            $peek = $stack->peek();
            if ($peek !== null &&
                in_array($peek, $this->cfg->availableLocales, true)) {
                $locale = (string)$stack->take();
            }
            $state->set($this->cfg->localeKey, $locale);

            // optional head: sessionId (only if cookies disabled)
            $sid = null;
            if (!$this->cfg->sessionUseCookies) {
                $p = $stack->peek();
                if ($p !== null &&
                    preg_match($this->cfg->sessionIdRegex, $p) === 1) {
                    $sid = (string)$stack->take();
                }
            }
            $state->set($this->cfg->sessionKey, $sid);

            // required-ish: page id (defaults to "main" if absent)
            $page = $stack->take();
            $state->set($this->cfg->pageKey, $page??'main');

            return new ParsedRoute($state, $stack);
        }

        // QUERY MODE
        // Locale affects external key names. Locale itself we resolve from "global domain" = "config".
        $locale = $this->cfg->defaultLocale;

        // Resolve locale from query using configured external key for canonical 'lang'
        $langExternal = $this->registry->externalKey(
            $locale,
            ['config'],
            $this->cfg->localeKey
        )??$this->cfg->localeKey;
        if (isset($query[$langExternal]) &&
            in_array(
                $query[$langExternal],
                $this->cfg->availableLocales,
                true
            )) {
            $locale = $query[$langExternal];
        }
        $state->set($this->cfg->localeKey, $locale);

        // Convert query external keys to canonical keys using domain search order.
        // Search order: ['config', pageDomain?, controllerDomain?] – at parse time we only know page AFTER mapping.
        // So we do two passes:
        // pass 1: map known global keys from 'config' domain
        foreach ($query as $extKey => $value) {
            $canon = $this->registry->canonicalKey(
                $locale,
                ['config'],
                $extKey
            );
            if ($canon !== null) {
                $state->set($canon, $value);
            }
        }

        // Resolve page using canonical key
        $pageExt = $this->registry->externalKey(
            $locale,
            ['config'],
            $this->cfg->pageKey
        )??$this->cfg->pageKey;
        $page = $query[$pageExt]??'main';
        $state->set($this->cfg->pageKey, $page);

        // session id in query when cookies disabled
        if (!$this->cfg->sessionUseCookies) {
            $sidExt = $this->registry->externalKey(
                $locale,
                ['config'],
                $this->cfg->sessionKey
            )??$this->cfg->sessionKey;
            $state->set($this->cfg->sessionKey, $query[$sidExt]??null);
        }

        // pass 2: map page-specific keys from domain "{Page}" (shortname convention)
        $pageDomain
            = $page; // your page id can be used as domain if you keep them aligned; otherwise map id->domain in registry
        foreach ($query as $extKey => $value) {
            $canon = $this->registry->canonicalKey(
                $locale,
                ['config', $pageDomain],
                $extKey
            );
            if ($canon !== null) {
                $state->set($canon, $value);
            }
        }

        return new ParsedRoute($state, new RouteStack([]));
    }

    /**
     * Build URL from a RouteState and controller-produced path segments.
     *
     * @param list<string> $pathSegments controller chain output (page controller + subcontrollers)
     */
    public function buildUrl(RouteState $state, array $pathSegments)
    : string {
        $locale = $state->get($this->cfg->localeKey, $this->cfg->defaultLocale)
                  ??
                  $this->cfg->defaultLocale;
        $sid = $state->get($this->cfg->sessionKey, null);

        if ($this->cfg->mode === UrlMode::REWRITE) {
            $segments = [];

            // optional head: locale (only include if not default)
            if ($locale !== $this->cfg->defaultLocale) {
                $segments[] = $locale;
            }

            // optional head: session id (if cookies disabled)
            if (!$this->cfg->sessionUseCookies && $sid !== null) {
                $segments[] = $sid;
            }

            // required page + tail segments
            $page = $state->get($this->cfg->pageKey, 'main')??'main';
            $segments[] = $page;
            $segments = array_merge($segments, $pathSegments);

            $path = implode('/', array_map('rawurlencode', $segments)) . '/';

            return rtrim($this->cfg->basePath, '/') . '/' . $path;
        }

        // QUERY MODE: external keys depend on locale and domain.
        $domains = ['config', $state->get($this->cfg->pageKey, 'main')??'main'];

        /** @var array<string,string> $externalQuery */
        $externalQuery = [];

        // Always include locale
        $langExt = $this->registry->externalKey(
            $locale,
            ['config'],
            $this->cfg->localeKey
        );
        if ($langExt === null) {
            $this->emitUndefinedQueryKey($locale, $this->cfg->localeKey);
            $langExt = $this->cfg->localeKey;
        }
        $externalQuery[$langExt] = $locale;

        // Include page
        $pageExt = $this->registry->externalKey(
            $locale,
            ['config'],
            $this->cfg->pageKey
        );
        if ($pageExt === null) {
            $this->emitUndefinedQueryKey($locale, $this->cfg->pageKey);
            $pageExt = $this->cfg->pageKey;
        }
        $externalQuery[$pageExt] = $state->get($this->cfg->pageKey, 'main')
                                   ??
                                   'main';

        // Session if cookies disabled
        if (!$this->cfg->sessionUseCookies && $sid !== null) {
            $sidExt = $this->registry->externalKey(
                $locale,
                ['config'],
                $this->cfg->sessionKey
            );
            if ($sidExt === null) {
                $this->emitUndefinedQueryKey($locale, $this->cfg->sessionKey);
                $sidExt = $this->cfg->sessionKey;
            }
            $externalQuery[$sidExt] = $sid;
        }

        // Tail segments become query params in query mode.
        // Convention: controller chain should also set canonical params in RouteState; then we externalize them.
        foreach ($state->all() as $canon => $val) {
            if ($val === null) {
                continue;
            }
            if ($canon === $this->cfg->localeKey ||
                $canon === $this->cfg->pageKey ||
                $canon === $this->cfg->sessionKey) {
                continue;
            }

            $ext = $this->registry->externalKey($locale, $domains, $canon);
            if ($ext === null) {
                $this->emitUndefinedQueryKey($locale, $canon);
                $ext = $canon; // fallback
            }
            $externalQuery[$ext] = $val;
        }

        $base = rtrim($this->cfg->basePath, '/') .
                '/' .
                ltrim($this->cfg->entryPoint, '/');
        $qs = http_build_query($externalQuery, '', '&');

        return $qs === '' ? $base : ($base . '?' . $qs);
    }

    private function emitUndefinedQueryKey(string $locale, string $canonicalKey)
    : void {
        $this->sink?->emit(
            new UndefinedQueryKeyDiagnostic(
                self::ID_UNDEFINED_QUERY_KEY,
                self::LVL_UNDEFINED_QUERY_KEY,
                $locale,
                $canonicalKey
            )
        );
    }
}