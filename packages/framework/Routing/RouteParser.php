<?php
declare(strict_types=1);

namespace AstrX\Routing;

use AstrX\Config\Config;
use AstrX\Http\Request;

/**
 * Reads the locale, an optional session id and the page token from a request.
 *
 * Two URL styles, chosen by Routing.url_rewrite:
 *   rewrite:  /<locale>/[api/][<sid>/]<page>/<tail…>
 *             (the locale segment is optional; without it the first segment
 *             is the page and every later one stays in the tail)
 *   query:    ?<locale_key>=en&<session_key>=…&<page_key>=…[&api=1]
 *
 * A session id is only taken from the URL in cookieless mode, and only when
 * it matches Session.session_id_regex. The parsed values are also written
 * into the CurrentUrl and the request's query bag, which is what the rest of
 * the framework reads them from.
 */
final class RouteParser
{
    /** @param list<string> $availableLocales */
    public function __construct(
        private readonly bool   $urlRewrite,
        private readonly string $basePath,
        private readonly array  $availableLocales,
        private readonly string $defaultLocale,
        private readonly bool   $sessionUseCookies,
        private readonly string $sessionIdRegex,
        private readonly string $localeKey,
        private readonly string $sessionKey,
        private readonly string $pageKey,
        private readonly string $defaultPageToken,
    ) {}

    /** Build from the Routing, Session and Prelude config sections. */
    public static function fromConfig(Config $config): self
    {
        $availableLocales = $config->getConfig('Prelude', 'available_languages', ['en']);
        $availableLocales = is_array($availableLocales)
            ? array_values(array_filter($availableLocales, 'is_string'))
            : ['en'];
        $defaultLocaleStr = $config->getConfigString('Prelude', 'default_language', 'en');

        // A locale is a plain string validated against available_languages
        // (config), NOT a fixed enum — so an admin can add a language from the
        // Language admin page without a code change. Fall back to the first
        // available locale, then 'en'.
        $defaultLocale = in_array($defaultLocaleStr, $availableLocales, true)
            ? $defaultLocaleStr
            : ($availableLocales[0] ?? 'en');

        return new self(
            urlRewrite:        $config->getConfigBool('Routing', 'url_rewrite', true),
            basePath:          $config->getConfigString('Routing', 'base_path', '/'),
            availableLocales:  $availableLocales,
            defaultLocale:     $defaultLocale,
            sessionUseCookies: $config->getConfigBool('Session', 'use_cookies', true),
            sessionIdRegex:    $config->getConfigString('Session', 'session_id_regex', '/^[\da-fA-F]{256}$/'),
            localeKey:         $config->getConfigString('Routing', 'locale_key', 'lang'),
            sessionKey:        $config->getConfigString('Routing', 'session_key', 'sid'),
            pageKey:           $config->getConfigString('Routing', 'page_key', 'page'),
            defaultPageToken:  $config->getConfigString('Routing', 'default_page', 'WORDING_MAIN'),
        );
    }

    /**
     * @param string $requestUri the raw request URI (rewrite mode reads the path from it)
     */
    public function parse(Request $request, CurrentUrl $current, string $requestUri): RouteMatch
    {
        $sidCandidate = null;

        if ($this->urlRewrite) {
            $stack      = UrlStack::fromRequest($requestUri, $this->basePath);

            $a = $stack->pop();

            $localeFromUrl = ($a !== null && in_array($a, $this->availableLocales, true));
            $locale        = ($localeFromUrl && $a !== null) ? $a : $this->defaultLocale;

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

            $current->set($this->localeKey, $locale);
            $request->query()->set($this->localeKey, $locale);

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
                !$this->sessionUseCookies
                && $b !== null
                && preg_match($this->sessionIdRegex, $b) === 1
            ) {
                $sidCandidate = $b;
                $current->set($this->sessionKey, $sidCandidate);
                $request->query()->set($this->sessionKey, $sidCandidate);
                $pageToken = $stack->pop() ?? $this->defaultPageToken;
            } else {
                $pageToken = $b ?? $this->defaultPageToken;
            }

            $current->set($this->pageKey, $pageToken);
            $request->query()->set($this->pageKey, $pageToken);

            // Store remaining path segments for controllers to consume as
            // page-specific sub-params (e.g. page number, sort order).
            $current->setTail($stack->remaining());

            return new RouteMatch($locale, $sidCandidate, $pageToken);
        }

        $rawLocale = $request->query()->get($this->localeKey);
        $locale    = (is_string($rawLocale) && in_array($rawLocale, $this->availableLocales, true))
            ? $rawLocale
            : $this->defaultLocale;

        $current->set($this->localeKey, $locale);
        $request->query()->set($this->localeKey, $locale);

        if (!$this->sessionUseCookies) {
            $rawSid = $request->query()->get($this->sessionKey);
            if (is_string($rawSid) && preg_match($this->sessionIdRegex, $rawSid) === 1) {
                $sidCandidate = $rawSid;
                $current->set($this->sessionKey, $sidCandidate);
                $request->query()->set($this->sessionKey, $sidCandidate);
            }
        }

        $rawPage   = $request->query()->get($this->pageKey);
        $pageToken = (is_string($rawPage) && $rawPage !== '') ? $rawPage : $this->defaultPageToken;

        $current->set($this->pageKey, $pageToken);
        $request->query()->set($this->pageKey, $pageToken);

        // API detection (query mode): ?api=1 turns the same page into an
        // API call. Mirror of the rewrite-mode /api/ segment above.
        $apiFlag = $request->query()->get('api');
        if ($apiFlag === '1' || $apiFlag === 'true') {
            $request->markAsApi();
        }

        return new RouteMatch($locale, $sidCandidate, $pageToken);
    }
}
