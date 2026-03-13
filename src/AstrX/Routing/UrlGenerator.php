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
    public function toPage(string $resolvedUrlId, array $queryParams = []): string
    {
        [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale] =
            $this->routingConfig();

        $extra = $queryParams !== [] ? '?' . http_build_query($queryParams) : '';

        if ($urlRewrite) {
            $base = rtrim($basePath, '/');
            $path = $locale !== ''
                ? $base . '/' . $locale . '/' . $resolvedUrlId
                : $base . '/' . $resolvedUrlId;
            return $path . $extra;
        }

        $query = [];
        if ($locale !== '') {
            $query[$localeKey] = $locale;
        }
        $query[$pageKey] = $resolvedUrlId;
        $query           = array_merge($query, $queryParams);

        return $entryPoint . '?' . http_build_query($query);
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
     * @param array<string, scalar> $extraQuery Additional query-string params
     *                                          appended in both modes.
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
    ): string {
        [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale] =
            $this->routingConfig();

        if ($urlRewrite) {
            return $this->rewriteSubPage(
                $basePath, $locale, $resolvedUrlId,
                $page, $order, $perPage,
                $defaultPage, $defaultOrder, $defaultPerPage,
                $extraQuery,
            );
        }

        return $this->querySubPage(
            $entryPoint, $localeKey, $locale, $pageKey, $resolvedUrlId,
            $page, $order, $perPage,
            $defaultPage, $defaultOrder, $defaultPerPage,
            $extraQuery,
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** @return array{bool, string, string, string, string, string} */
    private function routingConfig(): array
    {
        $urlRewrite  = (bool)   $this->config->getConfig('Routing', 'url_rewrite',  true);
        $basePath    = (string) $this->config->getConfig('Routing', 'base_path',    '/');
        $localeKey   = (string) $this->config->getConfig('Routing', 'locale_key',   'lang');
        $pageKey     = (string) $this->config->getConfig('Routing', 'page_key',     'page');
        $entryPoint  = (string) $this->config->getConfig('Routing', 'entry_point',  'index.php');
        $locale      = (string) $this->currentUrl->get($localeKey, '');

        return [$urlRewrite, $basePath, $localeKey, $pageKey, $entryPoint, $locale];
    }

    /** @param array<string, scalar> $extraQuery */
    private function rewriteSubPage(
        string $basePath,
        string $locale,
        string $resolvedUrlId,
        int    $page,
        string $order,
        int    $perPage,
        int    $defaultPage,
        string $defaultOrder,
        int    $defaultPerPage,
        array  $extraQuery,
    ): string {
        $base = rtrim($basePath, '/');
        $root = $locale !== ''
            ? $base . '/' . $locale . '/' . $resolvedUrlId
            : $base . '/' . $resolvedUrlId;

        // Build segments right-to-left, trimming trailing defaults.
        $segments = [];

        if ($perPage !== $defaultPerPage) {
            array_unshift($segments, (string) $perPage);
        }
        if ($order !== $defaultOrder || $segments !== []) {
            array_unshift($segments, $order);
        }
        if ($page !== $defaultPage || $segments !== []) {
            array_unshift($segments, (string) $page);
        }

        $path = $segments !== []
            ? $root . '/' . implode('/', $segments)
            : $root;

        $extra = $extraQuery !== [] ? '?' . http_build_query($extraQuery) : '';
        return $path . $extra;
    }

    /** @param array<string, scalar> $extraQuery */
    private function querySubPage(
        string $entryPoint,
        string $localeKey,
        string $locale,
        string $pageKey,
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

        return $entryPoint . '?' . http_build_query($query);
    }
}