<?php

declare(strict_types = 1);

namespace AstrX\Routing;

use AstrX\Config\Config;

/**
 * Builds internal page URLs respecting the configured routing mode.
 * Extracted from NavbarHandler so that any service or controller needing
 * to generate a link to an internal page uses the same logic.
 * URL-rewrite mode:  /{basePath}/{locale}/{resolvedUrlId}[?extraParams]
 * Query-string mode: {entryPoint}?{localeKey}={locale}&{pageKey}={resolvedUrlId}[&extraParams]
 * $resolvedUrlId must already be translated (e.g. 'principale' for IT, 'main' for EN).
 * The caller is responsible for resolving WORDING_ keys through the Translator first.
 * Extra query parameters (e.g. pagination: ['page' => 2]) are always appended
 * as a query string regardless of routing mode, since they are not part of the
 * path-based routing scheme.
 */
final class UrlGenerator
{
    public function __construct(
        private readonly Config $config,
        private readonly CurrentUrl $currentUrl,
    ) {
    }

    /**
     * Build the URL for an internal page.
     *
     * @param string $resolvedUrlId              Already-translated URL slug.
     * @param array<string, scalar> $extraParams Additional query-string parameters.
     */
    public function toPage(string $resolvedUrlId, array $extraParams = [])
    : string {
        $urlRewrite = (bool)$this->config->getConfig(
            'Routing',
            'url_rewrite',
            true
        );
        $basePath = (string)$this->config->getConfig(
            'Routing',
            'base_path',
            '/'
        );
        $localeKey = (string)$this->config->getConfig(
            'Routing',
            'locale_key',
            'lang'
        );
        $pageKey = (string)$this->config->getConfig(
            'Routing',
            'page_key',
            'page'
        );
        $entryPoint = (string)$this->config->getConfig(
            'Routing',
            'entry_point',
            'index.php'
        );

        $locale = (string)$this->currentUrl->get($localeKey, '');

        $extra = $extraParams !== [] ? '?' . http_build_query($extraParams) :
            '';

        if ($urlRewrite) {
            $base = rtrim($basePath, '/');
            $path = $locale !== '' ?
                $base . '/' . $locale . '/' . $resolvedUrlId :
                $base . '/' . $resolvedUrlId;

            return $path . $extra;
        }

        // Query-string mode
        $query = [];
        if ($locale !== '') {
            $query[$localeKey] = $locale;
        }
        $query[$pageKey] = $resolvedUrlId;
        $query = array_merge($query, $extraParams);

        return $entryPoint . '?' . http_build_query($query);
    }
}