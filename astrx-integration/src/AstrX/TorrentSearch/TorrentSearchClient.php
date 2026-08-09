<?php
declare(strict_types=1);

namespace AstrX\TorrentSearch;

/**
 * The torrent bridge: a zero-dependency HTTP client for the standalone torrentds
 * JSON API. No curl, no Composer — a plain `file_get_contents` over an http
 * stream context with a short timeout. AstrX talks only to the loopback engine;
 * the engine owns the DHT / BitTorrent hop.
 *
 * SECURITY MODEL
 * --------------
 *   * The host + scheme come only from {@see TorrentSearchConfig} (operator-
 *     trusted, normalised to an http(s) origin). The end user controls only the
 *     query, the page number and an infohash; the query is rawurlencode()d, the
 *     page is cast to int (and translated to torrentds' `limit`/`offset`), and
 *     the infohash is validated to lowercase hex before it is ever placed in a
 *     path. None of them can escape to the host/scheme. No SSRF surface.
 *   * Every network failure is swallowed (the `@` keeps the warning out of
 *     AstrX's error mask, where it would otherwise become an HTTP 500) and
 *     surfaces as a friendly `ok => false` payload — never a crash.
 *   * Torrent NAMES and FILE PATHS are attacker-controlled: they come from
 *     `.torrent` metadata harvested off the DHT. Every such text field is
 *     decoded, strip_tags()'d and whitespace-collapsed here, so the array
 *     returned to the controller contains NO markup at all. The template then
 *     renders each field through plain `{{ }}` (HTML-escaped) as a second,
 *     authoritative boundary.
 *   * The magnet URI and the `.torrent` download URL are REBUILT from a
 *     validated hex infohash + a rawurlencode()d clean name and the config
 *     origin — the engine's own `magnet` string is never trusted verbatim — so
 *     no attacker-controlled bytes can reach an href.
 *
 * torrentds paginates with `limit`/`offset`, not `page`; the page-based UI (a
 * carbon copy of the WebSearch/OnionSearch pages) is translated to those here.
 *
 * This class has NO AstrX dependencies beyond {@see TorrentSearchConfig}, so it
 * can be exercised in isolation (see tests/suite_bridge_test.php).
 */
final class TorrentSearchClient
{
    public function __construct(private readonly TorrentSearchConfig $config) {}

    /**
     * Query the backend and return a fully-sanitised, render-ready view model.
     *
     * @return array{
     *   ok: bool,
     *   total: int,
     *   page: int,
     *   page_size: int,
     *   results: list<array{
     *     infohash:string, name:string, size:string, size_bytes:int,
     *     file_count:int, seen_count:int, category:string,
     *     magnet:string, torrent_url:string, has_swarm:bool,
     *     seeders:int, leechers:int, swarm:string
     *   }>
     * }
     */
    public function search(string $query, int $page): array
    {
        $page     = max(1, $page);
        $perPage  = $this->config->perPage();
        $offset   = ($page - 1) * $perPage;

        $raw = $this->fetch('/api/search?q=' . rawurlencode($query)
            . '&limit=' . $perPage . '&offset=' . $offset);
        if ($raw === null) {
            return $this->unavailable($page, $perPage);
        }

        /** @var mixed $data */
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->unavailable($page, $perPage);
        }

        return [
            'ok'        => true,
            'total'     => max(0, self::i($data['total'] ?? null)),
            'page'      => $page,
            'page_size' => $perPage,
            'results'   => $this->mapResults($data['results'] ?? null),
        ];
    }

    /**
     * Fetch a single torrent's detail (file list + timestamps). The infohash is
     * validated to hex first; an invalid one short-circuits to "not found"
     * without a network call.
     *
     * @return array{
     *   ok: bool, found: bool,
     *   infohash:string, name:string, size:string, size_bytes:int,
     *   file_count:int, seen_count:int, category:string,
     *   first_seen:string, last_seen:string,
     *   magnet:string, torrent_url:string, has_torrent:bool,
     *   files: list<array{path:string, size:string, size_bytes:int}>
     * }
     */
    public function detail(string $infohash): array
    {
        $ih = self::normaliseInfohash($infohash);
        if ($ih === '') {
            return $this->detailUnavailable(false);
        }

        $raw = $this->fetch('/api/torrent/' . rawurlencode($ih));
        if ($raw === null) {
            return $this->detailUnavailable(true);
        }

        /** @var mixed $data */
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->detailUnavailable(true);
        }
        // torrentds answers a miss with {"error":"not found"} + HTTP 404.
        if (isset($data['error']) || !isset($data['infohash'])) {
            return $this->detailUnavailable(false);
        }

        $rowIh    = self::normaliseInfohash(self::s($data['infohash'] ?? null));
        $ihOut    = $rowIh !== '' ? $rowIh : $ih;
        $name     = self::cleanText(self::s($data['name'] ?? null));
        $sizeB    = max(0, self::i($data['total_size'] ?? null));

        return [
            'ok'          => true,
            'found'       => true,
            'infohash'    => $ihOut,
            'name'        => $name !== '' ? $name : $ihOut,
            'size'        => self::humanSize($sizeB),
            'size_bytes'  => $sizeB,
            'file_count'  => max(0, self::i($data['file_count'] ?? null)),
            'seen_count'  => max(0, self::i($data['seen_count'] ?? null)),
            'category'    => self::cleanText(self::s($data['category'] ?? null)),
            'first_seen'  => self::humanTime($data['first_seen'] ?? null),
            'last_seen'   => self::humanTime($data['last_seen'] ?? null),
            'magnet'      => self::magnet($ihOut, $name),
            'torrent_url' => $this->torrentUrl($ihOut, self::bool($data['has_torrent'] ?? null)),
            'has_torrent' => self::bool($data['has_torrent'] ?? null),
            'files'       => $this->mapFiles($data['files'] ?? null),
        ];
    }

    /**
     * Perform the GET. Returns the raw body, or null on any transport error /
     * timeout. `@` suppression is deliberate: a connection-refused warning is
     * in AstrX's error mask and would be promoted to a 500 otherwise.
     */
    private function fetch(string $pathAndQuery): ?string
    {
        $url = $this->config->baseUrl() . $pathAndQuery;

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
     * @return list<array{infohash:string, name:string, size:string, size_bytes:int, file_count:int, seen_count:int, category:string, magnet:string, torrent_url:string, has_swarm:bool, seeders:int, leechers:int, swarm:string}>
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
            $ih = self::normaliseInfohash(self::s($row['infohash'] ?? null));
            if ($ih === '') {
                // No valid infohash → no safe magnet / .torrent link → skip.
                continue;
            }
            $name    = self::cleanText(self::s($row['name'] ?? null));
            $sizeB   = max(0, self::i($row['total_size'] ?? null));
            $hasSwarm = isset($row['seeders']);
            $seeders  = $hasSwarm ? max(0, self::i($row['seeders'] ?? null)) : 0;
            $leechers = $hasSwarm ? max(0, self::i($row['leechers'] ?? null)) : 0;
            $out[] = [
                'infohash'    => $ih,
                'name'        => $name !== '' ? $name : $ih,
                'size'        => self::humanSize($sizeB),
                'size_bytes'  => $sizeB,
                'file_count'  => max(0, self::i($row['file_count'] ?? null)),
                'seen_count'  => max(0, self::i($row['seen_count'] ?? null)),
                'category'    => self::cleanText(self::s($row['category'] ?? null)),
                'magnet'      => self::magnet($ih, $name),
                // A search row has no has_torrent flag; always offer the link
                // (torrentds 404s cleanly if the blob is absent).
                'torrent_url' => $this->torrentUrl($ih, true),
                'has_swarm'   => $hasSwarm,
                'seeders'     => $seeders,
                'leechers'    => $leechers,
                // Pre-joined "seeders/leechers" for the results template. The engine
                // drops the loop-row context inside a truthy {{#has_swarm}} section,
                // so these numbers must be emitted at loop level via this field;
                // '' when the engine reported no swarm so the clause collapses.
                'swarm'       => $hasSwarm ? ($seeders . '/' . $leechers) : '',
            ];
        }
        return $out;
    }

    /**
     * Project the untrusted `files` array (paths are DHT-sourced) onto the
     * sanitised view model.
     *
     * @return list<array{path:string, size:string, size_bytes:int}>
     */
    private function mapFiles(mixed $files): array
    {
        if (!is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            if (!is_array($f)) {
                continue;
            }
            $path = self::cleanText(self::s($f['path'] ?? null));
            if ($path === '') {
                $path = '(unnamed)';
            }
            $len = max(0, self::i($f['length'] ?? null));
            $out[] = [
                'path'       => $path,
                'size'       => self::humanSize($len),
                'size_bytes' => $len,
            ];
        }
        return $out;
    }

    /**
     * @return array{ok:bool,total:int,page:int,page_size:int,results:list<array{infohash:string, name:string, size:string, size_bytes:int, file_count:int, seen_count:int, category:string, magnet:string, torrent_url:string, has_swarm:bool, seeders:int, leechers:int, swarm:string}>}
     */
    private function unavailable(int $page, int $perPage): array
    {
        return [
            'ok'        => false,
            'total'     => 0,
            'page'      => $page,
            'page_size' => $perPage,
            'results'   => [],
        ];
    }

    /**
     * @return array{ok:bool, found:bool, infohash:string, name:string, size:string, size_bytes:int, file_count:int, seen_count:int, category:string, first_seen:string, last_seen:string, magnet:string, torrent_url:string, has_torrent:bool, files:list<array{path:string, size:string, size_bytes:int}>}
     */
    private function detailUnavailable(bool $reachable): array
    {
        return [
            'ok'          => $reachable,
            'found'       => false,
            'infohash'    => '',
            'name'        => '',
            'size'        => '',
            'size_bytes'  => 0,
            'file_count'  => 0,
            'seen_count'  => 0,
            'category'    => '',
            'first_seen'  => '',
            'last_seen'   => '',
            'magnet'      => '',
            'torrent_url' => '',
            'has_torrent' => false,
            'files'       => [],
        ];
    }

    /**
     * Build the `.torrent` download URL from the config origin + a validated hex
     * infohash. Returns '' when the torrent has no downloadable blob or the
     * infohash is invalid. The origin is operator-trusted and the infohash is
     * pure hex, so the resulting URL carries no attacker-controlled bytes.
     */
    private function torrentUrl(string $infohash, bool $available): string
    {
        $ih = self::normaliseInfohash($infohash);
        if ($ih === '' || !$available) {
            return '';
        }
        return $this->config->baseUrl() . '/torrent/' . $ih . '.torrent';
    }

    /**
     * Build a magnet URI from a validated hex infohash + rawurlencode()d clean
     * name. Never derived from the engine's own magnet string, so it cannot
     * carry markup or quotes.
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

    /**
     * Reduce a BitTorrent infohash to safe lowercase hex, or '' if it is not a
     * plausible v1 (40-hex) / v2 (64-hex) infohash. This is what makes it safe
     * to drop into a URL path and a magnet xt.
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

    /** Format a unix timestamp as a UTC date, or '' when absent/zero. */
    private static function humanTime(mixed $v): string
    {
        $ts = self::i($v);
        return $ts > 0 ? gmdate('Y-m-d', $ts) : '';
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

    /** Cast mixed→bool safely (PHPStan level 10). */
    private static function bool(mixed $v): bool
    {
        return (bool) $v;
    }
}
