<?php
declare(strict_types=1);

namespace AstrX\SuiteAdmin;

/**
 * The suite status bridge: a zero-dependency HTTP client that probes the four
 * astrx-suite engines for liveness + a few key metrics, and submits an onion
 * seed to onioncrawler's control endpoint. No curl, no Composer — plain stream
 * wrappers with a short timeout.
 *
 * SECURITY MODEL
 * --------------
 *   * Every target host + scheme comes only from {@see SuiteAdminConfig}
 *     (operator-trusted, normalised to an http(s) origin). Only fixed,
 *     code-controlled paths are ever appended (`/health`, `/healthz`,
 *     `/metrics`, `/api/stats`, `/add`). The onion seed is a POST BODY field,
 *     never spliced into a URL. No user-supplied bytes reach the host/scheme.
 *     No SSRF surface.
 *   * Redirects are never followed (`follow_location: 0`) and bodies are capped
 *     at {@see MAX_BODY}, so a hostile or huge endpoint cannot make the panel
 *     chase a redirect off-box or buffer unbounded data.
 *   * Every network failure is swallowed (`@` on the transport call keeps the
 *     warning out of AstrX's error mask, where it would otherwise become an
 *     HTTP 500) and surfaces as a friendly DOWN card — never a crash.
 *   * Metric NAMES + values come from an untrusted backend body. Metric VALUES
 *     are coerced to finite floats (NaN/Inf dropped); metric NAMES are passed to
 *     the template only from the operator-configured allow-list here, and every
 *     value is rendered through plain `{{ }}` (HTML-escaped) regardless.
 *
 * This class has NO AstrX dependencies beyond {@see SuiteAdminConfig}, so it can
 * be exercised in isolation (see tests/suite_bridge_test.php).
 */
final class SuiteAdminClient
{
    /** Hard cap on a health/metrics/response body (defensive; metrics are tiny). */
    private const int MAX_BODY = 1 << 20; // 1 MiB

    /** Liveness fallbacks tried (in order) after each engine's configured path. */
    private const array HEALTH_FALLBACKS = ['/healthz', '/health', '/stats', '/api/stats', '/'];

    public function __construct(private readonly SuiteAdminConfig $config) {}

    /**
     * Probe all four engines and return one render-ready status row each.
     *
     * @return list<array{
     *   name:string, label:string, up:bool, latency_ms:?float, health_path:string,
     *   error:string, control:string,
     *   metrics: list<array{key:string, value:string, present:bool}>
     * }>
     */
    public function statuses(): array
    {
        return [
            $this->probeEngine(
                'gitweb', 'Read-only git web viewer',
                $this->config->gitwebBaseUrl(), '/health', '/metrics',
                ['gitweb_requests_total', 'gitweb_requests_in_flight', 'gitweb_uptime_seconds'],
                '' // display-only: gitweb exposes no control endpoint
            ),
            $this->probeEngine(
                'onioncrawler', 'Onion search / crawler',
                $this->config->onioncrawlerBaseUrl(), '/healthz', '/metrics',
                ['onioncrawler_pages', 'onioncrawler_hosts', 'onioncrawler_frontier_queued'],
                'onion_seed' // POST /add is wired below
            ),
            $this->probeEngine(
                'websearch', 'Clear-web search',
                $this->config->websearchBaseUrl(), '/healthz', '/metrics',
                ['websearch_docs', 'websearch_hosts', 'websearch_searches_total'],
                ''
            ),
            $this->probeEngine(
                'torrentds', 'Torrent DHT indexer',
                // /api/stats is JSON on purpose — exercises the JSON metrics parser.
                $this->config->torrentdsBaseUrl(), '/health', '/api/stats',
                ['torrents', 'pending', 'total_size'],
                ''
            ),
        ];
    }

    /**
     * Probe one engine: tolerant liveness (configured path + fallbacks, any 2xx
     * is UP) then a bounded metrics fetch parsed as Prometheus text OR JSON.
     * Never throws — any transport failure becomes a DOWN row.
     *
     * @param list<string> $metricKeys
     * @return array{name:string, label:string, up:bool, latency_ms:?float, health_path:string, error:string, control:string, metrics: list<array{key:string, value:string, present:bool}>}
     */
    public function probeEngine(
        string $name,
        string $label,
        string $baseUrl,
        string $healthPath,
        string $metricsPath,
        array  $metricKeys,
        string $control,
    ): array {
        [$up, $latency, $path, $error] = $this->checkHealth($baseUrl, $healthPath);

        /** @var array<string,float> $metrics */
        $metrics = [];
        if ($up) {
            $resp = $this->request($baseUrl . $metricsPath, 'GET', null, null, (float) $this->config->timeoutSeconds());
            if ($resp !== null && $resp['status'] >= 200 && $resp['status'] < 300) {
                $metrics = self::parseMetrics($resp['body'], $resp['content_type']);
            }
        }

        $surfaced = [];
        foreach ($metricKeys as $key) {
            $present  = array_key_exists($key, $metrics);
            $surfaced[] = [
                'key'     => $key,
                'value'   => $present ? self::formatNumber($metrics[$key]) : '—',
                'present' => $present,
            ];
        }

        return [
            'name'        => $name,
            'label'       => $label,
            'up'          => $up,
            'latency_ms'  => $latency,
            'health_path' => $path,
            'error'       => $error,
            'control'     => $control,
            'metrics'     => $surfaced,
        ];
    }

    /**
     * Liveness within a single timeout budget across the configured path + a few
     * known fallbacks. A refused/timed-out connection is a fast DOWN; a non-2xx
     * status just means "try the next path".
     *
     * @return array{0:bool, 1:?float, 2:string, 3:string}  [up, latency_ms, path, error]
     */
    private function checkHealth(string $baseUrl, string $healthPath): array
    {
        $candidates = [];
        foreach (array_merge([$healthPath], self::HEALTH_FALLBACKS) as $p) {
            if ($p !== '' && !in_array($p, $candidates, true)) {
                $candidates[] = $p;
            }
        }

        $budget   = (float) $this->config->timeoutSeconds();
        $deadline = microtime(true) + $budget;
        $lastErr  = 'unreachable';

        foreach ($candidates as $path) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0.05) {
                break;
            }
            $t0   = microtime(true);
            $resp = $this->request($baseUrl . $path, 'GET', null, null, min($budget, $remaining));
            if ($resp === null) {
                // Transport failure (refused/timeout/DNS) — not going to get
                // better on another path against the same host.
                return [false, null, '', 'unreachable'];
            }
            if ($resp['status'] >= 200 && $resp['status'] < 300) {
                $latency = round((microtime(true) - $t0) * 1000.0, 2);
                return [true, $latency, $path, ''];
            }
            $lastErr = 'http ' . $resp['status'];
        }

        return [false, null, '', $lastErr];
    }

    /**
     * Submit an onion seed URL to onioncrawler's `GET/POST /add` control
     * endpoint (the one control action any suite engine exposes). The seed is
     * sent as a form-encoded POST BODY field, never spliced into a URL.
     *
     * @return array{ok:bool, status:string, http_status:int, ok_count:int, dup:int, blocked:int, invalid:int}
     */
    public function submitOnionSeed(string $seed): array
    {
        $seed = trim($seed);
        if ($seed === '') {
            return self::seedResult(false, 'empty', 0, 0, 0, 0, 0);
        }

        $body = http_build_query(['url' => $seed]);
        $resp = $this->request(
            $this->config->onioncrawlerBaseUrl() . '/add',
            'POST',
            $body,
            'application/x-www-form-urlencoded',
            (float) $this->config->timeoutSeconds(),
        );

        if ($resp === null) {
            return self::seedResult(false, 'unreachable', 0, 0, 0, 0, 0);
        }
        $http = $resp['status'];
        if ($http === 401 || $http === 403) {
            // onioncrawler gates /add unless allow_public_submit (or admin creds).
            return self::seedResult(false, 'forbidden', $http, 0, 0, 0, 0);
        }

        /** @var mixed $data */
        $data   = json_decode($resp['body'], true);
        $ok     = is_array($data) ? max(0, self::i($data['ok'] ?? null)) : 0;
        $dup    = is_array($data) ? max(0, self::i($data['dup'] ?? null)) : 0;
        $block  = is_array($data) ? max(0, self::i($data['blocked'] ?? null)) : 0;
        $bad    = is_array($data) ? max(0, self::i($data['not-onion'] ?? null)) : 0;

        if ($ok >= 1)    { return self::seedResult(true,  'queued',    $http, $ok, $dup, $block, $bad); }
        if ($dup >= 1)   { return self::seedResult(false, 'duplicate', $http, $ok, $dup, $block, $bad); }
        if ($block >= 1) { return self::seedResult(false, 'blocked',   $http, $ok, $dup, $block, $bad); }
        if ($bad >= 1)   { return self::seedResult(false, 'invalid',   $http, $ok, $dup, $block, $bad); }
        return self::seedResult(false, 'error', $http, $ok, $dup, $block, $bad);
    }

    /**
     * @return array{ok:bool, status:string, http_status:int, ok_count:int, dup:int, blocked:int, invalid:int}
     */
    private static function seedResult(bool $ok, string $status, int $http, int $okCount, int $dup, int $blocked, int $invalid): array
    {
        return [
            'ok'          => $ok,
            'status'      => $status,
            'http_status' => $http,
            'ok_count'    => $okCount,
            'dup'         => $dup,
            'blocked'     => $blocked,
            'invalid'     => $invalid,
        ];
    }

    // -------------------------------------------------------------------------
    // Metrics parsing (Prometheus text OR JSON) — public static for isolation
    // -------------------------------------------------------------------------

    /**
     * Parse a metrics body as JSON *or* Prometheus text, auto-detecting which.
     * JSON is preferred when the content-type says so or the body opens with
     * `{`/`[`; otherwise Prometheus text is tried first. Either way both
     * strategies are attempted before giving up, so a mislabelled endpoint still
     * parses.
     *
     * @return array<string,float>
     */
    public static function parseMetrics(string $body, string $contentType = ''): array
    {
        $text = trim($body);
        if ($text === '') {
            return [];
        }
        $looksJson = str_contains(strtolower($contentType), 'json')
            || $text[0] === '{' || $text[0] === '[';

        if ($looksJson) {
            $json = self::tryFlattenJson($text);
            if ($json !== null) {
                return $json;
            }
        }

        $prom = self::parsePrometheus($text);
        if ($prom !== []) {
            return $prom;
        }

        // Last resort: it may have been unadvertised JSON.
        return self::tryFlattenJson($text) ?? [];
    }

    /** @return array<string,float>|null */
    private static function tryFlattenJson(string $text): ?array
    {
        /** @var mixed $data */
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return null;
        }
        return self::flattenJson($data);
    }

    /**
     * Parse Prometheus text-exposition `name value` lines. `#` HELP/TYPE
     * comments and blanks are ignored; a trailing timestamp is tolerated. A
     * labelled series (`name{a="b"} 3`) is stored under both the full token and
     * the bare base name (first series wins for the base name).
     *
     * @return array<string,float>
     */
    public static function parsePrometheus(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 2) {
                continue;
            }
            $name = $parts[0];
            $num  = self::toNumber($parts[1]);
            if ($num === null) {
                continue;
            }
            $base = strstr($name, '{', true);
            $base = $base === false ? $name : $base;
            if (!array_key_exists($base, $out)) {
                $out[$base] = $num;
            }
            if (str_contains($name, '{')) {
                $out[$name] = $num;
            }
        }
        return $out;
    }

    /**
     * Flatten a JSON object one level into numeric `key -> float` pairs.
     * Top-level scalars are kept; a nested object contributes `parent_child`
     * keys for its numeric leaves. Numeric strings are coerced. Lists, null and
     * deeper nesting are ignored — a status card wants a handful of numbers.
     *
     * @param array<array-key,mixed> $obj
     * @return array<string,float>
     */
    public static function flattenJson(array $obj): array
    {
        $out = [];
        foreach ($obj as $k => $v) {
            $key = (string) $k;
            if (is_bool($v)) {
                $out[$key] = $v ? 1.0 : 0.0;
            } elseif (is_int($v) || is_float($v)) {
                if (is_finite((float) $v)) {
                    $out[$key] = (float) $v;
                }
            } elseif (is_string($v)) {
                $num = self::toNumber($v);
                if ($num !== null) {
                    $out[$key] = $num;
                }
            } elseif (is_array($v)) {
                foreach ($v as $k2 => $v2) {
                    $sub = $key . '_' . (string) $k2;
                    if (is_bool($v2)) {
                        $out[$sub] = $v2 ? 1.0 : 0.0;
                    } elseif ((is_int($v2) || is_float($v2)) && is_finite((float) $v2)) {
                        $out[$sub] = (float) $v2;
                    } elseif (is_string($v2)) {
                        $num = self::toNumber($v2);
                        if ($num !== null) {
                            $out[$sub] = $num;
                        }
                    }
                }
            }
        }
        return $out;
    }

    /** Parse a Prometheus/JSON scalar to a finite float, else null (drops NaN/Inf). */
    private static function toNumber(string $text): ?float
    {
        $s = trim($text);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        $v = (float) $s;
        return is_finite($v) ? $v : null;
    }

    /** Render a metric for display: an integer when integral, else a short float. */
    private static function formatNumber(float $v): string
    {
        if (floor($v) === $v && abs($v) < 1.0e15) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(sprintf('%.6f', $v), '0'), '.');
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    /**
     * One HTTP request via the stream wrapper. Returns [status, content_type,
     * body] or null on any transport failure. `@fopen` keeps a connection-
     * refused warning out of AstrX's error mask (else a 500). Never follows a
     * redirect; caps the body at MAX_BODY.
     *
     * @return array{status:int, content_type:string, body:string}|null
     */
    private function request(string $url, string $method, ?string $body, ?string $contentType, float $timeout): ?array
    {
        $header = "Accept: application/json, text/plain, */*\r\nConnection: close\r\n";
        $http   = [
            'method'          => $method,
            'timeout'         => max(0.1, $timeout),
            'ignore_errors'   => true,   // read the body even on a 4xx/5xx status
            'follow_location' => 0,      // never chase a redirect (SSRF hardening)
            'max_redirects'   => 0,
        ];
        if ($body !== null) {
            $header       .= 'Content-Type: ' . ($contentType ?? 'application/x-www-form-urlencoded') . "\r\n";
            $http['content'] = $body;
        }
        $http['header'] = $header;

        $ctx = stream_context_create(['http' => $http]);
        // Total wall-clock budget for the whole exchange (connect + headers +
        // body), so the body read below cannot outlast it.
        $deadline = microtime(true) + max(0.1, $timeout);
        $fp  = @fopen($url, 'rb', false, $ctx);
        if ($fp === false) {
            return null;
        }
        $meta = stream_get_meta_data($fp);
        // The stream `timeout` only bounds a SINGLE read: a backend dribbling one
        // byte per <timeout window keeps every read alive, so stream_get_contents
        // would pin this request far past `timeout`. Read the body under a TOTAL
        // wall-clock deadline instead, so a slow-drip straggler is reaped near the
        // timeout. (fopen()'s status+header read is still bounded per-read by the
        // context timeout and, as a synchronous admin page, by max_execution_time.)
        $raw  = self::readCapped($fp, self::MAX_BODY, $deadline);
        fclose($fp);

        $status = 0;
        $ctype  = '';
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

        return [
            'status'       => $status,
            'content_type' => $ctype,
            'body'         => $raw,
        ];
    }

    /**
     * Read up to $max bytes from $fp under a TOTAL wall-clock $deadline (a
     * microtime(true) timestamp), so a slow-drip backend cannot pin the request
     * past its timeout. Non-blocking reads gated by stream_select against the
     * shrinking remaining budget; returns whatever was read when the deadline (or
     * EOF) is hit — a partial/empty body degrades to a friendly DOWN row upstream.
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
            // The loop guard (strlen($buf) <= $max) guarantees this is >= 1;
            // max(1, ...) makes that provable to the type checker.
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

    /** Cast mixed→int safely (PHPStan level 10). */
    private static function i(mixed $v, int $default = 0): int
    {
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : $default);
    }
}
