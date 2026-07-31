<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Config\Config;
use AstrX\Content\ContentService;
use AstrX\Module\ModuleRegistry;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;

/**
 * XML sitemap of the site's public pages (W/wcms proposal D).
 *
 * URL: /<locale>/sitemap.xml   (page row WORDING_SITEMAP, template=0, controller=1)
 *
 * Lists the locale home plus every live PUBLIC content page (never
 * private/unlisted/draft/scheduled/expired — {@see ContentService} applies that
 * policy). Written directly as XML like the Atom feed, 1-hour public cache.
 *
 * SECURITY: this is a public, cacheable document, so every URL is built
 * absolutely from site_url + locale + slug — NOT through UrlGenerator, which in
 * cookieless mode would inject the session id into a shared cache (a
 * session-hijack vector, exactly as the feed avoids). No sid ever appears here.
 */
final class SitemapController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector            $collector,
        private readonly Config         $config,
        private readonly CurrentUrl     $currentUrl,
        private readonly ContentService $content,
        private readonly ModuleRegistry $modules,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $lang   = $this->currentUrl->get('lang', 'en');
        $locale = is_scalar($lang) ? (string) $lang : 'en';
        $base   = $this->siteBase();

        /** @var list<array{loc:string,lastmod:string}> $urls */
        $urls = [];
        // Locale home.
        $urls[] = ['loc' => $base . '/' . rawurlencode($locale), 'lastmod' => ''];

        // Public content pages — only when the content module is enabled (its
        // pages 404 otherwise). URLs are built sid-free (see class docblock).
        if ($this->modules->enabled('content')) {
            $pagesSlug = $this->content->contentSlug();
            foreach ($this->content->sitemapPages() as $p) {
                $urls[] = [
                    'loc'     => $base . '/' . rawurlencode($locale)
                               . '/' . rawurlencode($pagesSlug)
                               . '/' . rawurlencode($p['slug']),
                    'lastmod' => $this->lastmod($p['updated_at']),
                ];
            }
        }

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . $this->xml($u['loc']) . '</loc>' . "\n";
            if ($u['lastmod'] !== '') {
                $xml .= '    <lastmod>' . $this->xml($u['lastmod']) . '</lastmod>' . "\n";
            }
            $xml .= '  </url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/xml; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            header('Content-Length: ' . (string) strlen($xml));
        }
        echo $xml;
        // Hard stop — a raw-bytes controller must not fall through to the
        // framework's NO_CONTENT handling (same rationale as FeedController).
        exit;
    }

    /** Absolute site base — configured site_url, else best-effort from the request. */
    private function siteBase(): string
    {
        $siteUrl = rtrim($this->config->getConfigString('EmailService', 'site_url', ''), '/');
        if ($siteUrl === '') {
            $scheme = ($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http';
            $host   = is_scalar($_SERVER['HTTP_HOST'] ?? null)
                ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
            $siteUrl = $scheme . '://' . $host;
        }
        return $siteUrl;
    }

    /** A DB timestamp string → W3C date (Y-m-d, UTC), or '' if unparseable. */
    private function lastmod(string $updatedAt): string
    {
        $ts = strtotime($updatedAt);
        return $ts === false ? '' : gmdate('Y-m-d', $ts);
    }

    private function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
