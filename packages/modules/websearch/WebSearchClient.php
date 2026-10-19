<?php
declare(strict_types=1);

namespace AstrX\WebSearch;

/**
 * The clear-web bridge: a zero-dependency HTTP client for the standalone
 * astrx-websearch JSON API. No curl, no Composer — a plain `file_get_contents`
 * over an http stream context with a short timeout.
 *
 * SECURITY MODEL
 * --------------
 *   * The host + scheme come only from {@see WebSearchConfig} (operator-trusted,
 *     normalised to an http(s) origin). The end user controls only `q` and
 *     `page`; `q` is rawurlencode()d and `page` is cast to int, so neither can
 *     escape the query string or reach the host/scheme. No SSRF surface.
 *   * Every network failure is swallowed (the `@` keeps the warning out of
 *     AstrX's error mask, where it would otherwise become an HTTP 500) and
 *     surfaces as a friendly `ok => false` payload — never a crash.
 *   * Results are CRAWLED, UNTRUSTED content. Every text field is decoded,
 *     strip_tags()'d and whitespace-collapsed here, so the array returned to
 *     the controller contains NO markup at all — not even the engine's own
 *     `<mark>` highlight. The template then renders each field through plain
 *     `{{ }}` (HTML-escaped) as a second, authoritative boundary. Result URLs
 *     are reduced to a safe href (http/https only, else `#`) so a hostile
 *     `javascript:` URL can never land in an anchor.
 *
 * This class has NO AstrX dependencies beyond {@see WebSearchConfig}, so it can
 * be exercised in isolation (see tests/bridge_test.php).
 */
final class WebSearchClient
{
    public function __construct(private readonly WebSearchConfig $config) {}

    /**
     * Query the backend and return a fully-sanitised, render-ready view model.
     *
     * @return array{
     *   ok: bool,
     *   total: int,
     *   page: int,
     *   page_size: int,
     *   results: list<array{title:string,url:string,href:string,host:string,snippet:string}>
     * }
     */
    public function search(string $query, int $page, string $type = '', string $sort = '', int $perPage = 0): array
    {
        $page = max(1, $page);

        $raw = $this->fetch($query, $page, $type, $sort, $perPage);
        if ($raw === null) {
            return $this->unavailable($page);
        }

        /** @var mixed $data */
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->unavailable($page);
        }

        return [
            'ok'        => true,
            'total'     => max(0, self::i($data['total'] ?? null)),
            'page'      => max(1, self::i($data['page'] ?? null, $page)),
            'page_size' => max(1, self::i($data['page_size'] ?? null, $this->config->perPage())),
            'results'   => $this->mapResults($data['results'] ?? null),
        ];
    }

    /**
     * Perform the GET. Returns the raw body, or null on any transport error /
     * timeout. `@` suppression is deliberate: a connection-refused warning is
     * in AstrX's error mask and would be promoted to a 500 otherwise.
     */
    private function fetch(string $query, int $page, string $type = '', string $sort = '', int $perPage = 0): ?string
    {
        $url = $this->config->baseUrl()
            . '/api/search?q=' . rawurlencode($query)
            . '&page=' . $page;
        // Whitelisted, code-controlled extras. The controller only ever passes
        // known-safe tokens; rawurlencode is belt-and-braces so nothing here can
        // alter the host/scheme (no SSRF surface).
        if ($type !== '') {
            $url .= '&type=' . rawurlencode($type);
        }
        if ($sort !== '') {
            $url .= '&sort=' . rawurlencode($sort);
        }
        if ($perPage > 0) {
            $url .= '&page_size=' . $perPage;
        }

        $context = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => $this->config->timeoutSeconds(),
            'ignore_errors'   => true,   // read the body even on a 4xx/5xx status
            'follow_location' => 0,      // never chase a redirect (SSRF hardening)
            'max_redirects'   => 0,
            'header'          => "Accept: application/json\r\nConnection: close\r\n",
        ]]);

        $raw = @file_get_contents($url, false, $context);
        return is_string($raw) ? $raw : null;
    }

    /**
     * Project the untrusted JSON `results` array onto the sanitised view model.
     *
     * @return list<array{title:string,url:string,href:string,host:string,snippet:string}>
     */
    private function mapResults(mixed $rows): array
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
                // Clear-web API field is `snippet_html` and carries <mark> markup.
                'snippet' => self::cleanText(self::s($row['snippet_html'] ?? null)),
            ];
        }
        return $out;
    }

    /**
     * @return array{ok:bool,total:int,page:int,page_size:int,results:list<array{title:string,url:string,href:string,host:string,snippet:string}>}
     */
    private function unavailable(int $page): array
    {
        return [
            'ok'        => false,
            'total'     => 0,
            'page'      => $page,
            'page_size' => $this->config->perPage(),
            'results'   => [],
        ];
    }

    /**
     * Reduce an untrusted string to tag-free plain text: decode entities first
     * (so an entity-hidden `<script>` is surfaced), strip ALL tags, then
     * collapse whitespace. The result contains no `<`/`>` markup, so even before
     * the template's `{{ }}` escaping nothing raw from the engine survives.
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
