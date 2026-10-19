<?php
declare(strict_types=1);

namespace AstrX\Content;

use AstrX\Page\SitemapSource;

/**
 * The live, public content pages, for /sitemap.xml — ContentService applies
 * the visibility rules, so nothing private, unlisted, draft, scheduled or
 * expired is ever listed.
 */
final class ContentSitemap implements SitemapSource
{
    public function __construct(private readonly ContentService $content) {}

    public function sitemapEntries(): array
    {
        $base = $this->content->contentSlug();
        $out  = [];
        foreach ($this->content->sitemapPages() as $page) {
            $out[] = ['path' => [$base, $page['slug']], 'updated' => $page['updated_at']];
        }
        return $out;
    }
}
