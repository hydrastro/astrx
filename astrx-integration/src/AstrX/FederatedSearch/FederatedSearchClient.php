<?php
declare(strict_types=1);

namespace AstrX\FederatedSearch;

/**
 * The federated bridge: a zero-dependency HTTP client that fans a single query
 * out to the three standalone astrx-suite JSON search engines — websearch
 * (clear web), onioncrawler (onion) and torrentds (torrents) — for the unified
 * search page. The fourth "source", internal AstrX content, is served in-process
 * by {@see \AstrX\Search\SiteSearchService} and never touches this class. No
 * curl, no Composer — plain stream wrappers with a short timeout and a hard body
 * cap.
 *
 * SECURITY MODEL
 * --------------
 *   * Every target host + scheme comes only from {@see FederatedSearchConfig}
 *     (operator-trusted, normalised to an http(s) origin). Only the fixed,
 *     code-controlled path `/api/search?…` is ever appended; the end user
 *     controls only the query `q`, which is rawurlencode()d, so it can never
 *     escape the query string or reach the host/scheme. No SSRF surface.
 *   * Redirects are never followed (`follow_location: 0`) and bodies are capped
 *     at {@see MAX_BODY} under a total wall-clock deadline, so a hostile or huge
 *     or slow-drip endpoint cannot make the page chase a redirect off-box or
 *     buffer/hang on unbounded data — timeouts AND response sizes are bounded.
 *   * Every network failure is swallowed (`@` on the transport call keeps the
 *     warning out of AstrX's error mask, where it would otherwise become an HTTP
 *     500) and surfaces as a friendly `ok => false` payload — never a crash, so
 *     one dead source never fails the unified page.
 *   * Results are CRAWLED / DHT-sourced, UNTRUSTED content. Every text field is
 *     entity-decoded, strip_tags()'d and whitespace-collapsed here, so the array
 *     returned to the controller contains NO markup at all — not even an engine's
 *     own `<mark>` highlight. The template then renders every field through plain
 *     `{{ }}` (HTML-escaped) as a second, authoritative boundary. Result URLs are
 *     reduced to a safe href (http/https only, else `#`); the torrent magnet and
 *     `.torrent` URL are REBUILT from a validated hex infohash + rawurlencode()d
 *     clean name, never trusted verbatim.
 *
 * This class has NO AstrX dependencies beyond {@see FederatedSearchConfig}, so it
 * can be exercised in isolation (see tests/suite_bridge_test.php).
 */
final class FederatedSearchClient
{
    /** Hard cap on a search response body (defensive; a page of hits is tiny). */
    private const int MAX_BODY = 1 << 20; // 1 MiB

    public function __construct(private readonly FederatedSearchConfig $config) {}

    /**
     * Query the clear-web engine and return a fully-sanitised, render-ready model.
     *
     * @return array{ok:bool, results:list<array{title:string,url:string,href:string,host:string,snippet:string}>}
     */
    public function searchWeb(string $query): array
    {
        // Clear-web API field is `snippet_html` and carries <mark> markup.
        return $this->searchLink($this->config->websearchBaseUrl(), $query, 'snippet_html');
    }

    /**
     * Query the onion engine and return a fully-sanitised, render-ready model.
     *
     * @return array{ok:bool, results:list<array{title:string,url:string,href:string,host:string,snippet:string}>}
     */
    public function searchOnion(string $query): array
    {
        return $this->searchLink($this->config->onioncrawlerBaseUrl(), $query, 'snippet');
    }

    /**
     * Shared driver for the two link-style engines (websearch / onioncrawler),
     * which share the {title,url,host,snippet} result shape and `/api/search?q=`
     * pagination. Caps to the configured per-source result count.
     *
     * @return array{ok:bool, results:list<array{title:string,url:string,href:string,host:string,snippet:string}>}
     */
    private function searchLink(string $baseUrl, string $query, string $snippetKey): array
    {
        $resp = $this->request($baseUrl . '/api/search?q=' . rawurlencode($query) . '&page=1');
        if ($resp === null) {
            return ['ok' => false, 'results' => []];
        }
        /** @var mixed $data */
        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'results' => []];
        }
        $rows = self::parseLinkResults($data['results'] ?? null, $snippetKey);
        return ['ok' => true, 'results' => array_slice($rows, 0, $this->config->perPage())];
    }

    /**
     * Query the torrentds engine and return a fully-sanitised, render-ready model.
     * torrentds paginates with `limit`/`offset`; the unified view only ever shows
     * the first page, so offset is fixed at 0 and limit at the per-source cap.
     *
     * @return array{ok:bool, results:list<array{infohash:string,name:string,size:string,file_count:int,seen_count:int,category:string,magnet:string,torrent_url:string,has_torrent:bool,has_swarm:bool,seeders:int,leechers:int,swarm:string}>}
     */
    public function searchTorrent(string $query): array
    {
        $baseUrl = $this->config->torrentdsBaseUrl();
        $resp    = $this->request(
            $baseUrl . '/api/search?q=' . rawurlencode($query)
            . '&limit=' . $this->config->perPage() . '&offset=0'
        );
        if ($resp === null) {
            return ['ok' => false, 'results' => []];
        }
        /** @var mixed $data */
        $data = json_decode($resp['body'], true);
        if (!is_array($data)) {
            return ['ok' => false, 'results' => []];
        }
        $rows = self::parseTorrentResults($data['results'] ?? null, $baseUrl);
        return ['ok' => true, 'results' => array_slice($rows, 0, $this->config->perPage())];
    }

    // -------------------------------------------------------------------------
    // Parsing / sanitisation (public static for isolated testing)
    // -------------------------------------------------------------------------

    /**
     * Project an untrusted link-style `results` array (websearch / onioncrawler)
     * onto the sanitised view model. Every field is tag-stripped; the URL is
     * reduced to a safe http(s) href.
     *
     * @return list<array{title:string,url:string,href:string,host:string,snippet:string}>
     */
    public static function parseLinkResults(mixed $rows, string $snippetKey): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = self::cleanText(self::s($row['url'] ?? null));
            $out[] = [
                'title'   => self::cleanText(self::s($row['title'] ?? null)),
                'url'     => $url,
                'href'    => self::safeHref($url),
                'host'    => self::cleanText(self::s($row['host'] ?? null)),
                'snippet' => self::cleanText(self::s($row[$snippetKey] ?? null)),
            ];
        }
        return $out;
    }

    /**
     * Project an untrusted torrentds `results` array onto the sanitised view
     * model. A row whose infohash is not plausible hex is DROPPED (no safe magnet
     * or .torrent link is derivable); the magnet + .torrent URL are rebuilt from
     * the validated hex infohash and the operator-trusted origin.
     *
     * @return list<array{infohash:string,name:string,size:string,file_count:int,seen_count:int,category:string,magnet:string,torrent_url:string,has_torrent:bool,has_swarm:bool,seeders:int,leechers:int,swarm:string}>
     */
    public static function parseTorrentResults(mixed $rows, string $origin): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ih = self::normaliseInfohash(self::s($row['infohash'] ?? null));
            if ($ih === '') {
                continue;
            }
            $name     = self::cleanText(self::s($row['name'] ?? null));
            $sizeB    = max(0, self::i($row['total_size'] ?? null));
            $hasSwarm = isset($row['seeders']);
            $seeders  = $hasSwarm ? max(0, self::i($row['seeders'] ?? null)) : 0;
            $leechers = $hasSwarm ? max(0, self::i($row['leechers'] ?? null)) : 0;
            $out[] = [
                'infohash'    => $ih,
                'name'        => $name !== '' ? $name : $ih,
                'size'        => self::humanSize($sizeB),
                'file_count'  => max(0, self::i($row['file_count'] ?? null)),
                'seen_count'  => max(0, self::i($row['seen_count'] ?? null)),
                'category'    => self::cleanText(self::s($row['category'] ?? null)),
                'magnet'      => self::magnet($ih, $name),
                'torrent_url' => rtrim($origin, '/') . '/torrent/' . $ih . '.torrent',
                'has_torrent' => true,
                'has_swarm'   => $hasSwarm,
                'seeders'     => $seeders,
                'leechers'    => $leechers,
                // Pre-joined "seeders/leechers" for the results template. The engine
                // drops the loop-row context inside a truthy {{#has_swarm}} section,
                // so the numbers cannot be rendered from within it — they are emitted
                // at loop level via this field, and it is '' when the engine reported
                // no swarm so the whole clause collapses cleanly.
                'swarm'       => $hasSwarm ? ($seeders . '/' . $leechers) : '',
            ];
        }
        return $out;
    }

    // -------------------------------------------------------------------------
    // Sanitisation helpers (standalone; mirror the per-source clients)
    // -------------------------------------------------------------------------

    /**
     * Reduce an untrusted string to tag-free plain text: decode entities first
     * (so an entity-hidden `<script>` is surfaced), strip ALL tags, then collapse
     * whitespace. The result contains no `<`/`>` markup, so even before the
     * template's `{{ }}` escaping nothing raw from an engine survives.
     */
    private static function cleanText(string $s): string
    {
        if ($s === '') {
            return '';
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $collapsed = preg_replace('/\s+/u', ' ', $s);
        return trim(is_string($collapsed) ? $collapsed : $s);
    }

    /**
     * A link target safe to drop into an href: the URL only if it is http(s),
     * otherwise `#`. Blocks javascript:/data: and other scheme-based XSS.
     */
    private static function safeHref(string $url): string
    {
        if ($url === '') {
            return '#';
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : '';
        return in_array($scheme, ['http', 'https'], true) ? $url : '#';
    }

    /**
     * Reduce a BitTorrent infohash to safe lowercase hex, or '' if it is not a
     * plausible v1 (40-hex) / v2 (64-hex) infohash. This is what makes it safe to
     * drop into a URL path and a magnet xt.
     */
    private static function normaliseInfohash(string $s): string
    {
        $s = strtolower(trim($s));
        if ($s === '') {
            return '';
        }
        if (preg_match('/^[0-9a-f]{40}$/', $s) === 1
            || preg_match('/^[0-9a-f]{64}$/', $s) === 1) {
            return $s;
        }
        return '';
    }

    /**
     * Build a magnet URI from a validated hex infohash + rawurlencode()d clean
     * name. Never derived from the engine's own magnet string, so it cannot carry
     * markup or quotes.
     */
    private static function magnet(string $infohash, string $name): string
    {
        $ih = self::normaliseInfohash($infohash);
        if ($ih === '') {
            return '';
        }
        $magnet = 'magnet:?xt=urn:btih:' . $ih;
        if ($name !== '') {
            $magnet .= '&dn=' . rawurlencode($name);
        }
        return $magnet;
    }

    /** Human-readable byte size (binary units), mirroring torrentds' own display. */
    private static function humanSize(int $bytes): string
    {
        $n     = (float) max(0, $bytes);
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $last  = count($units) - 1;
        foreach ($units as $i => $unit) {
            if ($n < 1024 || $i === $last) {
                return $unit === 'B'
                    ? sprintf('%d %s', (int) $n, $unit)
                    : sprintf('%.1f %s', $n, $unit);
            }
            $n /= 1024;
        }
        return sprintf('%d B', $bytes);
    }

    // -------------------------------------------------------------------------
    // Transport (bounded timeout + bounded body; mirrors SuiteAdminClient)
    // -------------------------------------------------------------------------

    /**
     * One GET via the stream wrapper. Returns [status, content_type, body] or
     * null on any transport failure. `@fopen` keeps a connection-refused warning
     * out of AstrX's error mask (else a 500). Never follows a redirect; caps the
     * body at MAX_BODY under a total wall-clock deadline.
     *
     * @return array{status:int, content_type:string, body:string}|null
     */
    private function request(string $url): ?array
    {
        $timeout = (float) $this->config->timeoutSeconds();
        $ctx     = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => max(0.1, $timeout),
            'ignore_errors'   => true,   // read the body even on a 4xx/5xx status
            'follow_location' => 0,      // never chase a redirect (SSRF hardening)
            'max_redirects'   => 0,
            'header'          => "Accept: application/json\r\nConnection: close\r\n",
        ]]);

        // Total wall-clock budget for the whole exchange (connect + headers +
        // body), so the body read below cannot outlast it.
        $deadline = microtime(true) + max(0.1, $timeout);
        $fp = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return null;
        }
        $meta = stream_get_meta_data($fp);
        $raw  = self::readCapped($fp, self::MAX_BODY, $deadline);
        fclose($fp);

        $status  = 0;
        $ctype   = '';
        $wrapper = $meta['wrapper_data'] ?? null;
        if (is_array($wrapper)) {
            foreach ($wrapper as $line) {
                if (!is_string($line)) {
                    continue;
                }
                if ($status === 0 && preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $line, $m) === 1) {
                    $status = (int) $m[1];
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $ctype = trim(substr($line, 13));
                }
            }
        }

        return ['status' => $status, 'content_type' => $ctype, 'body' => $raw];
    }

    /**
     * Read up to $max bytes from $fp under a TOTAL wall-clock $deadline (a
     * microtime(true) timestamp), so a slow-drip backend cannot pin the request
     * past its timeout. Non-blocking reads gated by stream_select against the
     * shrinking remaining budget; returns whatever was read when the deadline (or
     * EOF) is hit — a partial/empty body just degrades to `ok => false` upstream.
     *
     * @param resource $fp
     */
    private static function readCapped($fp, int $max, float $deadline): string
    {
        stream_set_blocking($fp, false);
        $buf = '';
        while (strlen($buf) <= $max) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $read   = [$fp];
            $write  = null;
            $except = null;
            $sec    = (int) $remaining;
            $usec   = (int) (($remaining - $sec) * 1000000);
            $ready  = @stream_select($read, $write, $except, $sec, $usec);
            if ($ready === false || $ready === 0) {
                break; // select error, or no data arrived within the budget
            }
            $chunk = fread($fp, max(1, min(65536, $max + 1 - strlen($buf))));
            if ($chunk === '' || $chunk === false) {
                if (feof($fp)) {
                    break; // EOF — whole body read
                }
                continue;
            }
            $buf .= $chunk;
        }
        return substr($buf, 0, $max);
    }

    /** Cast mixed→string safely (PHPStan level 10). */
    private static function s(mixed $v): string
    {
        return is_scalar($v) ? (string) $v : '';
    }

    /** Cast mixed→int safely (PHPStan level 10). */
    private static function i(mixed $v, int $default = 0): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);
    }
}
