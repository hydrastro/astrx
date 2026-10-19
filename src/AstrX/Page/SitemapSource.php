<?php
declare(strict_types=1);

namespace AstrX\Page;

/**
 * Public pages a module adds to /sitemap.xml. A module extension point
 * (manifest `extensions`); only ENABLED modules are asked, since a disabled
 * module's pages 404.
 */
interface SitemapSource
{
    /**
     * Each entry: the URL path segments after the locale (unencoded; the
     * sitemap encodes them) and when the page last changed ('' when unknown,
     * else a date/time string strtotime() understands).
     *
     * List only what a search engine may index: never private, unlisted,
     * draft, scheduled or expired pages.
     *
     * @return list<array{path:list<string>,updated:string}>
     */
    public function sitemapEntries(): array;
}
