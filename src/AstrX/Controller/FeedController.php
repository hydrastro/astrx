<?php
declare(strict_types=1);

namespace AstrX\Controller;

use AstrX\Config\Config;
use AstrX\Http\Request;
use AstrX\I18n\Translator;
use AstrX\News\NewsRepository;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;
use AstrX\Routing\CurrentUrl;

/**
 * Serves the site's news as an Atom 1.0 feed.
 *
 * URL: /<locale>/feed.xml   (page row WORDING_FEED, template=0, controller=1)
 *
 * Atom over RSS because: (a) it's the modern standard, (b) it requires
 * absolute IDs and dates, which forces us to do the right thing, and (c)
 * its strictness means newsboat / NetNewsWire / Inoreader all parse it
 * without quirks.
 *
 * No template engine — the controller writes XML directly. Caching is
 * a 1-hour Cache-Control public; clients pick up new entries on their
 * usual poll cadence.
 *
 * Returns 20 most recent VISIBLE news items. Hidden news (admin-suppressed)
 * never appear in the feed — same policy as the website itself.
 */
final class FeedController extends AbstractController
{
    public function __construct(
        DiagnosticsCollector              $collector,
        private readonly Request          $request,
        private readonly NewsRepository   $news,
        private readonly Translator       $t,
        private readonly CurrentUrl       $currentUrl,
        private readonly Config           $config,
    ) {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $newsResult = $this->news->fetchRecent(20);
        $newsResult->drainTo($this->collector);
        $items = $newsResult->isOk() ? $newsResult->unwrap() : [];

        $lang     = $this->currentUrl->get('lang', 'en');
        $locale   = is_scalar($lang) ? (string) $lang : 'en';
        $siteUrl  = rtrim($this->config->getConfigString('EmailService', 'site_url', ''), '/');
        $siteName = $this->config->getConfigString('EmailService', 'site_name', 'AstrX');

        // If site_url isn't configured, build a best-effort absolute base
        // from the current request. Beats emitting relative URLs that break
        // in feed readers.
        if ($siteUrl === '') {
            $scheme = ($_SERVER['HTTPS'] ?? '') !== '' ? 'https' : 'http';
            $host   = is_scalar($_SERVER['HTTP_HOST'] ?? null)
                ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
            $siteUrl = $scheme . '://' . $host;
        }

        $homeUrl = $siteUrl . '/' . $locale;
        $selfUrl = $siteUrl . $this->request->uri()->path();
        $feedId  = $selfUrl;   // Atom requires a stable globally-unique id

        // Updated = newest item's created_at, or now if no items.
        $newest = $items[0]['created_at'] ?? '';
        $updated = $newest !== '' ? (string) $newest : gmdate('Y-m-d\TH:i:s\Z');

        $xml  = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<feed xmlns="http://www.w3.org/2005/Atom" xml:lang="' . $this->xmlAttr($locale) . '">' . "\n";
        $xml .= '  <title>' . $this->xmlText($siteName) . '</title>' . "\n";
        $xml .= '  <subtitle>' . $this->xmlText($this->t->t('feed.subtitle', fallback: 'Latest news')) . '</subtitle>' . "\n";
        $xml .= '  <id>' . $this->xmlText($feedId) . '</id>' . "\n";
        $xml .= '  <link rel="self" href="' . $this->xmlAttr($selfUrl) . '" type="application/atom+xml"/>' . "\n";
        $xml .= '  <link rel="alternate" href="' . $this->xmlAttr($homeUrl) . '" type="text/html"/>' . "\n";
        $xml .= '  <updated>' . $this->xmlText($updated) . '</updated>' . "\n";
        $xml .= '  <generator uri="https://github.com/anthropics/astrx" version="1.0">' . $this->xmlText($siteName) . '</generator>' . "\n";

        foreach ($items as $item) {
            $id      = is_scalar($item['id']         ?? null) ? (int)    $item['id']         : 0;
            $title   = is_scalar($item['title']      ?? null) ? (string) $item['title']      : '';
            $content = is_scalar($item['content']    ?? null) ? (string) $item['content']    : '';
            $created = is_scalar($item['created_at'] ?? null) ? (string) $item['created_at'] : $updated;

            // Per-item URI — points back to the news index. A future fix
            // could add deep links to individual news pages; for now the
            // homepage is the canonical view.
            $entryUrl = $homeUrl . '#news-' . $id;
            $entryId  = $siteUrl . '/news/' . $id;   // stable id, NOT a URL

            $xml .= '  <entry>' . "\n";
            $xml .= '    <title>' . $this->xmlText($title) . '</title>' . "\n";
            $xml .= '    <id>' . $this->xmlText($entryId) . '</id>' . "\n";
            $xml .= '    <link rel="alternate" href="' . $this->xmlAttr($entryUrl) . '" type="text/html"/>' . "\n";
            $xml .= '    <published>' . $this->xmlText($created) . '</published>' . "\n";
            $xml .= '    <updated>'   . $this->xmlText($created) . '</updated>' . "\n";
            $xml .= '    <content type="html">' . $this->xmlText($content) . '</content>' . "\n";
            $xml .= '  </entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/atom+xml; charset=utf-8');
            // 1-hour cache. Aggressive enough to be polite to scrapers,
            // gentle enough that fresh news shows up reasonably quickly.
            header('Cache-Control: public, max-age=3600');
            header('Content-Length: ' . (string) strlen($xml));
        }
        echo $xml;
        // Hard stop — see CaptchaImageController for rationale (raw-bytes
        // controllers must not let the framework's NO_CONTENT fallback run).
        exit;
    }

    /** Escape a string for XML text content. */
    private function xmlText(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Escape a string for XML attribute value. */
    private function xmlAttr(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
