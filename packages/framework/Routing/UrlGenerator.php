<?php
declare(strict_types=1);

namespace AstrX\Routing;

use AstrX\Config\Config;

/**
 * Builds internal page URLs respecting the configured routing mode.
 *
 * Two methods:
 *
 *   toPage($resolvedUrlId, $queryParams)
 *     — bare page URL, query params always appended as ?key=val.
 *     — Used by: NavbarHandler, form actions, anywhere linking to a page root.
 *
 *   toSubPage($resolvedUrlId, $page, $order, $perPage, $defaultPage,
 *             $defaultOrder, $defaultPerPage)
 *     — page URL with pagination/filter sub-params.
 *     — Rewrite mode: sub-params become path segments.
 *       /en/main/3/asc/10
 *     — Query mode: sub-params become named query params.
 *       index.php?lang=en&page=main&pn=3&order=asc&show=10
 *     — Trailing segments equal to their defaults are omitted in rewrite mode
 *       so /en/main/1/desc/20 becomes /en/main when all are default.
 *
 * Sub-param key names (query mode):
 *   pn    — page number  (avoids conflict with routing 'page' key)
 *   order — 'asc' or 'desc'
 *   show  — items per page
 *
 * Cookieless sessions: when `Session.use_cookies` is false, the session id must
 * travel in every generated URL or navigation loses the session (Tor operators
 * may disable browser cookies entirely). The sid is injected as a path segment
 * in rewrite mode ([locale]/<sid>/page/…, matching the router's expected slot)
 * and as the `Routing.session_key` query param in query mode. In cookie mode the
 * sid is empty here and URLs are unchanged. Only INTERNAL page URLs pass through
 * this class, so an external link never receives a sid.
 *
 * $resolvedUrlId must already be translated for the current locale.
 */
final class UrlGenerator
{
    public function __construct(
        private readonly Config     $config,
        private readonly CurrentUrl $currentUrl,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Build the URL for a page root (no sub-params).
     *
     * @param array<string, scalar> $queryParams Always appended as ?key=val.
     */
    public function toPage(string $resolvedUrlId, array $queryParams = [], bool $includeSid = true): string
    {
        [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale, $sessionKey, $sid] =
            $this->routingConfig();

        // R12: callers that build a URL destined to leave the origin (e.g. a link
        // emailed to a clearnet inbox) pass includeSid:false so the cookieless
        // session id is never serialized into it (a hijack vector — see
        // EmailService::buildTokenLink and the sitemap/feed sid-free policy).
        if (!$includeSid) { $sid = ''; }

        $extra = $queryParams !== [] ? '?' . http_build_query($queryParams) : '';

        if ($urlRewrite) {
            $base = rtrim($basePath, '/');
            // Segment order MUST match the router: [locale] / [sid] / page / tail
            $segs = [];
            if ($locale !== '') { $segs[] = $locale; }
            if ($sid    !== '') { $segs[] = $sid; }
            $segs[] = $resolvedUrlId;
            return $this->withRoutePrefix($base . '/' . implode('/', $segs) . $extra);
        }

        $query = [];
        if ($locale !== '') {
            $query[$localeKey] = $locale;
        }
        $query[$pageKey] = $resolvedUrlId;
        $query           = array_merge($query, $queryParams);
        if ($sid !== '') {
            // After the merge so a caller's $queryParams can't drop/override it.
            $query[$sessionKey] = $sid;
        }

        return $this->withRoutePrefix($entryPoint . '?' . http_build_query($query));
    }

    /**
     * Build a page URL with pagination/filter sub-params.
     *
     * In rewrite mode, sub-params become positional path segments:
     *   /en/main/3/asc/10
     * Trailing segments that equal their respective defaults are stripped:
     *   page=1, order='desc', perPage=default → /en/main (bare)
     *   page=3, order='desc', perPage=default → /en/main/3
     *   page=1, order='asc',  perPage=10      → /en/main/1/asc/10
     *
     * In query mode, sub-params become named query parameters:
     *   index.php?lang=en&page=main&pn=3&order=asc&show=10
     * Default values are also omitted in query mode for clean URLs.
     *
     * @param array<string, scalar> $extraQuery Additional query-string params.
     * @param list<string> $pathSegments Path segments appended after page root.
     */
    public function toSubPage(
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage    = 1,
        string $defaultOrder   = 'desc',
        int    $defaultPerPage = 20,
        array  $extraQuery     = [],
        array  $pathSegments   = [],  // rewrite-mode extra segments appended after primary
    ): string {
        [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale, $sessionKey, $sid] =
            $this->routingConfig();

        if ($urlRewrite) {
            return $this->rewriteSubPage(
                $basePath, $locale, $sid, $resolvedUrlId,
                $page, $order, $perPage,
                $defaultPage, $defaultOrder, $defaultPerPage,
                $extraQuery, $pathSegments,
            );
        }

        return $this->querySubPage(
            $entryPoint, $localeKey, $locale, $pageKey, $sessionKey, $sid, $resolvedUrlId,
            $page, $order, $perPage,
            $defaultPage, $defaultOrder, $defaultPerPage,
            $extraQuery,
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array{bool, string, string, string, string, string, string, string} */
    private function routingConfig(): array
    {
        $urlRewrite  = $this->config->getConfigBool('Routing', 'url_rewrite',  true);
        $basePath    = $this->config->getConfigString('Routing', 'base_path',    '/');
        $localeKey   = $this->config->getConfigString('Routing', 'locale_key',   'lang');
        $pageKey     = $this->config->getConfigString('Routing', 'page_key',     'page');
        $entryPoint  = $this->config->getConfigString('Routing', 'entry_point',  'index.php');
        $localeRaw   = $this->currentUrl->get($localeKey, '');
        $locale      = is_string($localeRaw) ? $localeRaw : '';

        // Cookieless session propagation (see class docblock). Read the live sid
        // from CurrentUrl (ContentManager stamps it there post-regeneration), but
        // ONLY when the deployment is cookieless — otherwise $sid stays '' and no
        // URL is altered.
        $sessionKey  = $this->config->getConfigString('Routing', 'session_key', 'sid');
        $useCookies  = $this->config->getConfigBool('Session', 'use_cookies', true);
        $sid         = '';
        if (!$useCookies) {
            // Read the LIVE session id, NOT a CurrentUrl snapshot: the login path
            // calls session_regenerate_id(true) mid-request (UserService::login),
            // so an id stamped into CurrentUrl at routing time goes stale and the
            // post-login redirect would carry a destroyed sid — landing the user
            // on a dead session (logged out). session_id() always reflects the
            // current, post-regeneration id. (Matches DefaultTemplateContext.)
            $sidVal = session_id();
            $sid    = is_string($sidVal) && $sidVal !== '' ? $sidVal : '';
        }

        return [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale, $sessionKey, $sid];
    }

    /**
     * @param array<string, scalar> $extraQuery
     * @param list<string>          $pathSegments  Extra segments appended after primary.
     *                                             When non-empty all three primary segments
     *                                             are always emitted so positions are unambiguous.
     */
    private function rewriteSubPage(
        string $basePath,
        string $locale,
        string $sid,
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage,
        string $defaultOrder,
        int    $defaultPerPage,
        array  $extraQuery,
        array  $pathSegments = [],
    ): string {
        $base = rtrim($basePath, '/');
        // Root = [locale] / [sid] / page  (sid slot matches the router, §parse).
        $rootSegs = [];
        if ($locale !== '') { $rootSegs[] = $locale; }
        if ($sid    !== '') { $rootSegs[] = $sid; }
        $rootSegs[] = $resolvedUrlId;
        $root = $base . '/' . implode('/', $rootSegs);

        if ($pathSegments !== []) {
            // When secondary segments are present, all three primary segments must
            // be emitted so their positions are fixed and unambiguous to the router.
            $primary = [(string) $page, $order, (string) $perPage];
        } else {
            // Standard right-to-left trailing-default stripping.
            $primary = [];
            if ($perPage !== $defaultPerPage) {
                array_unshift($primary, (string) $perPage);
            }
            if ($order !== $defaultOrder || $primary !== []) {
                array_unshift($primary, $order);
            }
            if ($page !== $defaultPage || $primary !== []) {
                array_unshift($primary, (string) $page);
            }
        }

        $allSegments = array_merge($primary, $pathSegments);
        $path = $allSegments !== []
            ? $root . '/' . implode('/', $allSegments)
            : $root;

        $extra = $extraQuery !== [] ? '?' . http_build_query($extraQuery) : '';
        return $this->withRoutePrefix($path . $extra);
    }

    /** @param array<string, scalar> $extraQuery */
    private function querySubPage(
        string $entryPoint,
        string $localeKey,
        string $locale,
        string $pageKey,
        string $sessionKey,
        string $sid,
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage,
        string $defaultOrder,
        int    $defaultPerPage,
        array  $extraQuery,
    ): string {
        $query = [];
        if ($locale !== '') {
            $query[$localeKey] = $locale;
        }
        $query[$pageKey] = $resolvedUrlId;

        if ($page !== $defaultPage) {
            $query['pn'] = $page;
        }
        if ($order !== $defaultOrder) {
            $query['order'] = $order;
        }
        if ($perPage !== $defaultPerPage) {
            $query['show'] = $perPage;
        }

        $query = array_merge($query, $extraQuery);
        if ($sid !== '') {
            $query[$sessionKey] = $sid;
        }

        return $this->withRoutePrefix($entryPoint . '?' . http_build_query($query));
    }

    private function withRoutePrefix(string $url): string
    {
        if (!defined('ASTRX_COMPILED_ROUTE_PREFIX')) {
            return $url;
        }

        $prefixValue = constant('ASTRX_COMPILED_ROUTE_PREFIX');
        $prefix = is_scalar($prefixValue) ? (string) $prefixValue : '';
        $prefix = '/' . trim($prefix, '/');
        if ($prefix === '/') {
            return $url;
        }

        if ($url === '' || str_starts_with($url, '#')) {
            return $url;
        }

        $lower = strtolower($url);
        if (str_starts_with($lower, 'http://')
            || str_starts_with($lower, 'https://')
            || str_starts_with($lower, 'mailto:')
            || str_starts_with($lower, 'tel:')
            || str_starts_with($lower, 'data:')
            || str_starts_with($lower, 'javascript:')) {
            return $url;
        }

        if (str_starts_with($url, $prefix . '/') || $url === $prefix) {
            return $url;
        }

        if ($url[0] === '/') {
            return $prefix . ($url === '/' ? '' : $url);
        }

        // Query-mode entry points are often configured as "index.php".
        // In compiled benchmark mode they must remain inside /compile.
        if (preg_match('#^[A-Za-z0-9_.-]+\.php(?:\?|$)#', $url) === 1) {
            return $prefix . '/' . $url;
        }

        return $url;
    }
}
