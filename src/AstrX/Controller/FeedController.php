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

        // Updated = newest item's created_at, or now if no items. R4-23: format
        // from a UNIX timestamp with gmdate() so the "Z" really is UTC on a
        // non-UTC host (matching the board feed), instead of a DB-timezone
        // string mislabelled with a trailing Z.
        $newestTs = (isset($items[0]['created_ts']) && is_scalar($items[0]['created_ts']))
            ? (int) $items[0]['created_ts']
            : time();
        $updated  = gmdate('Y-m-d\TH:i:s\Z', $newestTs);

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
            $id        = is_scalar($item['id']         ?? null) ? (int)    $item['id']         : 0;
            $title     = is_scalar($item['title']      ?? null) ? (string) $item['title']      : '';
            $content   = is_scalar($item['content']    ?? null) ? (string) $item['content']    : '';
            $createdTs = is_scalar($item['created_ts'] ?? null) ? (int)    $item['created_ts'] : $newestTs;
            $created   = gmdate('Y-m-d\TH:i:s\Z', $createdTs);

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
            // R10 LOW: the homepage renders news via an ESCAPED placeholder
            // ({{content}} in main.html), i.e. news bodies are shown as literal
            // text, not HTML. Declaring type="html" here would make a feed reader
            // XML-unescape and then interpret the body AS HTML — diverging from
            // the on-site view (and letting an authored '<' become a tag). type=
            // "text" makes readers render it literally, matching the site.
            $xml .= '    <content type="text">' . $this->xmlText($content) . '</content>' . "\n";
            $xml .= '  </entry>' . "\n";
        }

        $xml .= '</feed>' . "\n";

        http_response_code(200);
        if (!headers_sent()) {
            header('Content-Type: application/atom+xml; charset=utf-8');
            // R11 (LOW): private, no-store. In cookieless REWRITE mode the feed's
            // self/entry links (built via UrlGenerator / the request path) carry
            // the URL session id, and 'public' let a shared cache store — and
            // potentially serve cross-user — that sid-bearing document. Never
            // shared-cache a body that can contain a session id.
            header('Cache-Control: private, no-store');
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
        return htmlspecialchars(self::stripXmlControlChars($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Escape a string for XML attribute value. */
    private function xmlAttr(string $s): string
    {
        return htmlspecialchars(self::stripXmlControlChars($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * Remove characters that are illegal in XML 1.0 even when escaped (C0 controls
     * except TAB/LF/CR). htmlspecialchars entity-escapes markup but cannot make a
     * raw 0x0C form-feed legal, so a copy-pasted control char in a news title/body
     * would otherwise produce a document strict readers reject wholesale.
     */
    private static function stripXmlControlChars(string $s): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    }
}
