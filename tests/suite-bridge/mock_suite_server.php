<?php
declare(strict_types=1);

/**
 * Tiny mock of the astrx-suite engines' HTTP surfaces, used only by
 * suite_bridge_test.php. Run via:  php -S 127.0.0.1:<port> mock_suite_server.php
 *
 * It emulates, from ONE process (all engine base URLs point here):
 *   torrentds : GET /api/search   (JSON results + pagination; a result NAME
 *                                   carries <script>/entities and one row has a
 *                                   bogus infohash to prove it is dropped)
 *               GET /api/torrent/<ih> (JSON detail; file PATHS carry markup)
 *               GET /api/stats    (JSON metrics — exercises the JSON parser)
 *               GET /health       (JSON liveness)
 *   engines   : GET /healthz      (text "ok" liveness)
 *               GET /metrics      (Prometheus text — exercises the text parser,
 *                                   incl. a labelled series)
 *   onioncrawler control :
 *               POST /add         (records method/path/url to $MOCK_ADD_LOG and
 *                                   returns the submit_many counts JSON)
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path   = is_string($path) ? $path : '/';

$IH1 = str_repeat('a', 40);   // valid 40-hex infohash
$IH2 = str_repeat('b', 40);   // valid 40-hex infohash

function send_json(mixed $obj, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($obj, JSON_UNESCAPED_SLASHES);
}

// ── liveness ────────────────────────────────────────────────────────────────
if ($path === '/healthz') {
    header('Content-Type: text/plain');
    echo 'ok';
    return true;
}
if ($path === '/health') {
    send_json(['status' => 'ok', 'torrents' => 1234, 'pending' => 5, 'uptime_seconds' => 42.5]);
    return true;
}

// ── metrics: Prometheus text (gitweb/onioncrawler/websearch) ──────────────────
if ($path === '/metrics') {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    echo implode("\n", [
        '# HELP gitweb_requests_total total requests',
        '# TYPE gitweb_requests_total counter',
        'gitweb_requests_total 907',
        'gitweb_requests_in_flight 2',
        'gitweb_uptime_seconds 3600.5',
        'onioncrawler_pages 4096',
        'onioncrawler_hosts 128',
        'onioncrawler_frontier_queued 77',
        'websearch_docs 55000',
        'websearch_hosts 900',
        'websearch_searches_total 12345',
        // a labelled series — the base name must still resolve
        'http_requests_total{code="200"} 500',
        'not_a_number NaN',
    ]) . "\n";
    return true;
}

// ── metrics: JSON (torrentds /api/stats) ──────────────────────────────────────
if ($path === '/api/stats') {
    send_json([
        'torrents'   => 1234,
        'files'      => 56789,
        'total_size' => 9876543210,
        'pending'    => 5,
        'dht_nodes'  => 42,
    ]);
    return true;
}

// ── torrentds search ──────────────────────────────────────────────────────────
if ($path === '/api/search') {
    global $IH1, $IH2;
    send_json([
        'query'  => isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '',
        'count'  => 3,
        'total'  => 42,
        'limit'  => isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 25,
        'offset' => isset($_GET['offset']) && is_numeric($_GET['offset']) ? (int) $_GET['offset'] : 0,
        'results' => [
            [
                'infohash'   => $IH1,
                'name'       => 'Ubuntu <script>alert(1)</script> &amp; more',
                'total_size' => 1610612736,   // 1.5 GiB
                'file_count' => 3,
                'piece_count'=> 100,
                'seen_count' => 17,
                'category'   => 'software',
                'magnet'     => 'magnet:?xt=urn:btih:' . $IH1 . '&dn=whatever',
                'seeders'    => 12,
                'leechers'   => 3,
                'completed'  => 40,
                // Link-style fields (consumed by the federated web/onion parsers).
                'title'      => 'Ubuntu <script>alert(1)</script> download page',
                'url'        => 'http://example.com/ubuntu',
                'host'       => 'example.com',
                'snippet_html' => 'the <mark>ubuntu</mark> release &amp; notes',
                'snippet'    => 'the ubuntu release &amp; <b>notes</b>',
            ],
            [
                'infohash'   => $IH2,
                'name'       => 'Debian &lt;b&gt;netinst&lt;/b&gt;',
                'total_size' => 402653184,    // 384 MiB
                'file_count' => 1,
                'piece_count'=> 40,
                'seen_count' => 9,
                'category'   => 'software',
                'magnet'     => 'magnet:?xt=urn:btih:' . $IH2,
                'title'      => 'Debian &lt;b&gt;netinst&lt;/b&gt; mirror',
                'url'        => 'http://onion.example/deb',
                'host'       => 'onion.example',
                'snippet_html' => 'debian net installer',
                'snippet'    => 'debian net installer',
            ],
            [
                // Bogus infohash — the client MUST drop this row (no safe links).
                'infohash'   => 'not-a-real-infohash',
                'name'       => 'poisoned <img src=x onerror=alert(1)>',
                'total_size' => 1,
                'file_count' => 1,
                'seen_count' => 1,
                'category'   => 'other',
                'magnet'     => 'javascript:alert(1)',
                // A hostile link row: javascript: URL must be reduced to '#'.
                'title'      => 'poisoned <img src=x onerror=alert(1)> link',
                'url'        => 'javascript:alert(1)',
                'host'       => 'evil',
                'snippet_html' => 'x',
                'snippet'    => 'x',
            ],
        ],
    ]);
    return true;
}

// ── torrentds detail ──────────────────────────────────────────────────────────
if (str_starts_with($path, '/api/torrent/')) {
    global $IH1;
    $ih = strtolower(trim(substr($path, strlen('/api/torrent/')), '/'));
    if ($ih !== $IH1) {
        send_json(['error' => 'not found'], 404);
        return true;
    }
    send_json([
        'infohash'    => $IH1,
        'name'        => 'Ubuntu <script>alert(1)</script> &amp; more',
        'total_size'  => 1610612736,
        'file_count'  => 2,
        'seen_count'  => 17,
        'category'    => 'software',
        'piece_length'=> 262144,
        'first_seen'  => 1700000000,
        'last_seen'   => 1710000000,
        'has_torrent' => true,
        'torrent'     => '/torrent/' . $IH1 . '.torrent',
        'magnet'      => 'magnet:?xt=urn:btih:' . $IH1 . '&dn=whatever',
        'files'       => [
            ['path' => 'ubuntu/<script>evil.txt</script>', 'length' => 1048576],
            ['path' => 'ubuntu/install &amp; readme.txt',  'length' => 2048],
        ],
    ]);
    return true;
}

// ── onioncrawler control: POST /add ───────────────────────────────────────────
if ($path === '/add') {
    if ($method !== 'POST') {
        send_json(['error' => 'method not allowed'], 405);
        return true;
    }
    $raw = file_get_contents('php://input');
    $raw = is_string($raw) ? $raw : '';
    parse_str($raw, $fields);
    $url = isset($fields['url']) && is_string($fields['url']) ? $fields['url'] : '';

    // Record what we actually received so the test can assert "posts to /add".
    $log = getenv('MOCK_ADD_LOG');
    if (is_string($log) && $log !== '') {
        @file_put_contents($log, json_encode([
            'method' => $method,
            'path'   => $path,
            'url'    => $url,
        ], JSON_UNESCAPED_SLASHES));
    }

    // Mirror submit_many's aggregate-counts shape: one newly-queued seed.
    send_json([
        'ok' => 1, 'dup' => 0, 'not-onion' => 0, 'blocked' => 0,
        'results' => [['status' => 'ok', 'url' => $url]],
    ]);
    return true;
}

// ── blocklist control: onioncrawler POST /blocklist, torrentds POST /api/block ─
if ($path === '/blocklist' || $path === '/api/block') {
    if ($method !== 'POST') {
        send_json(['error' => 'method not allowed'], 405);
        return true;
    }
    $raw = file_get_contents('php://input');
    $raw = is_string($raw) ? $raw : '';
    parse_str($raw, $fields);
    $kind       = isset($fields['kind'])  && is_string($fields['kind'])  ? $fields['kind']  : '';
    $value      = isset($fields['value']) && is_string($fields['value']) ? $fields['value'] : '';
    $fieldToken = isset($fields['token']) && is_string($fields['token']) ? $fields['token'] : '';

    $hdrToken = isset($_SERVER['HTTP_X_ADMIN_TOKEN']) && is_string($_SERVER['HTTP_X_ADMIN_TOKEN']) ? $_SERVER['HTTP_X_ADMIN_TOKEN'] : '';
    $authHdr  = isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION']) ? $_SERVER['HTTP_AUTHORIZATION'] : '';
    $bearer   = stripos($authHdr, 'Bearer ') === 0 ? trim(substr($authHdr, 7)) : '';

    $expected = getenv('MOCK_ADMIN_TOKEN');
    $expected = is_string($expected) && $expected !== '' ? $expected : 's3cr3t-token';

    $presented = $fieldToken !== '' ? $fieldToken : ($hdrToken !== '' ? $hdrToken : $bearer);

    // Record what arrived so the test can assert kind/value + the auth shapes.
    $log = getenv('MOCK_BLOCK_LOG');
    if (is_string($log) && $log !== '') {
        @file_put_contents($log, json_encode([
            'method'     => $method,
            'path'       => $path,
            'kind'       => $kind,
            'value'      => $value,
            'token'      => $presented,
            'via_field'  => $fieldToken === $expected,
            'via_header' => $hdrToken === $expected,
            'via_bearer' => $bearer === $expected,
        ], JSON_UNESCAPED_SLASHES));
    }

    if ($presented !== $expected) {
        send_json(['error' => 'forbidden'], 403);
        return true;
    }
    // A "dupe" value lets the test exercise the client's duplicate sniff.
    if (strtolower($value) === 'dupe') {
        send_json(['ok' => true, 'status' => 'duplicate', 'kind' => $kind, 'value' => $value]);
        return true;
    }
    send_json(['ok' => true, 'status' => 'added', 'kind' => $kind, 'value' => $value]);
    return true;
}

http_response_code(404);
echo 'not found';
return true;
